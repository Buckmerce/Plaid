<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Refund;

use Buckmerce\Plaid\Exception\PaymentAttemptBusyException;
use Buckmerce\Plaid\Exception\PaymentException;
use Buckmerce\Plaid\Exception\PersistenceException;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Payment\MerchantNotifier;
use Buckmerce\Plaid\Payment\MonitoringPolicy;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\OrderPaymentProjector;
use Buckmerce\Plaid\Payment\PaymentAlerts;
use Buckmerce\Plaid\Payment\PaymentMonitor;
use Buckmerce\Plaid\Payment\PaymentSnapshot;
use Buckmerce\Plaid\Payment\PaymentStateMachine;
use Buckmerce\Plaid\Payment\ReturnListener;
use Buckmerce\Plaid\Persistence\DatabaseMutex;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Plaid\DTO\TransferRefund;
use Buckmerce\Plaid\Plaid\Exception\PlaidApiException;
use Buckmerce\Plaid\Plaid\Exception\PlaidException;
use Buckmerce\Plaid\Plaid\Refund\TransferRefundService;
use Buckmerce\Plaid\Plaid\Transfer\TransferService;
use Buckmerce\Plaid\Settings\AccountScope;
use Buckmerce\Plaid\Settings\Settings;
use Buckmerce\Plaid\Support\Money;
use Buckmerce\Plaid\Support\SiteMarker;

/**
 * Native WooCommerce refunds through Plaid /transfer/refund/*.
 *
 * Duplicate-refund safety:
 * - refund creation for one order is serialized by a database mutex;
 * - the refundable amount is recomputed under that mutex from Buckmerce's refund store;
 * - a refund is reserved durably (unique idempotency key, unique WooCommerce refund) before
 *   Plaid is called, and the same key is sent to Plaid, so retries cannot create a second
 *   refund; an identical amount within a minute is refused as an accidental double submit;
 * - an ambiguous create (timeout, 5xx) is retried once with the same key and otherwise left
 *   "uncertain": it blocks further refunds until Plaid's refund list proves whether the refund
 *   exists. Buckmerce never re-sends an uncertain create later on its own.
 *
 * Refund state changes come from verified refund events, /transfer/refund/get
 * (reconciliation, manual sync) and the create response; each is applied exactly once.
 */
final class RefundService implements ReturnListener
{
    public const DUPLICATE_WINDOW_SECONDS = 60;
    /** Refunds of this age or older are checked against Plaid's refund list when uncertain. */
    private const ADOPTION_CLOCK_TOLERANCE_SECONDS = 600;

    /**
     * @param \Closure(): TransferRefundService $plaid_refunds
     * @param \Closure(): TransferService       $transfers
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly \Closure $plaid_refunds,
        private readonly \Closure $transfers,
        private readonly RefundStore $store,
        private readonly PaymentAlerts $alerts,
        private readonly Logger $logger,
        private readonly MerchantNotifier $notifier = new MerchantNotifier()
    ) {
    }

    public function eligibility(\WC_Order $order): RefundEligibility
    {
        return RefundPolicy::evaluate($order, $this->settings, $this->store->for_order($order->get_id()));
    }

    /**
     * Refunds $amount for a WooCommerce refund object that WooCommerce has just saved.
     * Safe to call again for the same WooCommerce refund: it never creates a second refund.
     */
    public function refund(\WC_Order $order, ?\WC_Order_Refund $wc_refund, string $amount, string $reason = ''): RefundOutcome
    {
        try {
            $amount = Money::transfer_amount($amount);
        } catch (PaymentException) {
            return RefundOutcome::error(__('Enter a refund amount greater than zero with at most two decimal places.', 'buckmerce-plaid'));
        }
        if (null === $wc_refund || $wc_refund->get_id() < 1 || $wc_refund->get_parent_id() !== $order->get_id()) {
            return RefundOutcome::error(__('Pay by Bank refunds must be created from the WooCommerce order screen.', 'buckmerce-plaid'));
        }
        if (! Money::same_amount(Money::transfer_amount((string) $wc_refund->get_amount()), $amount)) {
            return RefundOutcome::error(__('The refund amount does not match the WooCommerce refund.', 'buckmerce-plaid'));
        }
        $existing = $this->store->find_by_wc_refund($wc_refund->get_id());
        if (null !== $existing) {
            return $this->outcome_for($existing);
        }
        try {
            return DatabaseMutex::with('refund:' . $order->get_id(), function (DatabaseMutex $mutex) use ($order, $wc_refund, $amount, $reason): RefundOutcome {
                $order = wc_get_order($order->get_id());
                if (! $order instanceof \WC_Order) {
                    return RefundOutcome::error(__('The order could not be loaded.', 'buckmerce-plaid'));
                }
                $existing = $this->store->find_by_wc_refund($wc_refund->get_id());
                if (null !== $existing) {
                    return $this->outcome_for($existing);
                }
                $eligibility = $this->eligibility($order);
                if (! $eligibility->allowed || null === $eligibility->snapshot) {
                    return RefundOutcome::error($eligibility->message);
                }
                if (Money::to_cents($amount) > Money::to_cents($eligibility->remaining)) {
                    return RefundOutcome::error(sprintf(
                        /* translators: %s: remaining refundable amount */
                        __('The refund exceeds the amount that can still be refunded through Plaid ($%s).', 'buckmerce-plaid'),
                        $eligibility->remaining
                    ));
                }
                foreach ($eligibility->records as $record) {
                    if (RefundState::is_active($record->status) && Money::same_amount($record->amount, $amount) && $record->age() < self::DUPLICATE_WINDOW_SECONDS) {
                        return RefundOutcome::error(__('An identical refund was issued for this order less than a minute ago. If you really want a second refund of the same amount, wait a minute and try again.', 'buckmerce-plaid'));
                    }
                }
                $snapshot = $eligibility->snapshot;
                $reservation = $this->store->reserve(array(
                    'order_id' => $order->get_id(),
                    'wc_refund_id' => $wc_refund->get_id(),
                    'environment' => $snapshot->environment,
                    'account_fp' => $this->settings->account_fingerprint(),
                    'attempt_id' => $snapshot->attempt_id,
                    'transfer_id' => $eligibility->transfer_id,
                    'idempotency_key' => self::idempotency_key($snapshot, $wc_refund->get_id(), $amount),
                    'amount' => $amount,
                    'currency' => $snapshot->currency,
                ));
                $record = $reservation['record'];
                if (null === $record) {
                    $existing = $this->store->find_by_idempotency_key(self::idempotency_key($snapshot, $wc_refund->get_id(), $amount));
                    return null === $existing ? RefundOutcome::error(__('The refund could not be recorded safely, so it was not sent to Plaid. No money was moved.', 'buckmerce-plaid')) : $this->outcome_for($existing);
                }
                $mutex->assert_owned();
                return $this->create_remote($order, $wc_refund, $record, $reservation['owner_token'], $reason);
            });
        } catch (PaymentAttemptBusyException) {
            return RefundOutcome::error(__('Another refund for this order is being processed. Wait a moment, reload the order and check its refunds before trying again.', 'buckmerce-plaid'));
        } catch (PersistenceException $exception) {
            $this->logger->log('error', 'refund_reservation_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            return RefundOutcome::error(__('The refund could not be recorded safely, so it was not sent to Plaid. No money was moved.', 'buckmerce-plaid'));
        }
    }

    /**
     * Deterministic, per-intended-refund Plaid idempotency key (≤ 50 characters): bound to
     * this store, environment, order, WooCommerce refund, payment attempt and amount.
     */
    public static function idempotency_key(PaymentSnapshot $snapshot, int $wc_refund_id, string $amount): string
    {
        $material = implode('|', array('buckmerce-refund', SiteMarker::current(), $snapshot->environment, (string) $snapshot->order_id, (string) $wc_refund_id, $snapshot->attempt_id, $amount));
        return 'bmfp-' . substr(hash('sha256', $material), 0, 44);
    }

    private function create_remote(\WC_Order $order, \WC_Order_Refund $wc_refund, RefundRecord $record, string $owner_token, string $reason): RefundOutcome
    {
        $refund = null;
        $last_error = null;
        for ($try = 0; $try < 2 && null === $refund; ++$try) {
            try {
                $refund = ($this->plaid_refunds)()->create($record->transfer_id, $record->amount, $record->idempotency_key);
            } catch (PlaidApiException $exception) {
                $last_error = $exception;
                if (! $exception->is_transient()) {
                    return $this->rejected($order, $record, $owner_token, $exception);
                }
                if (! $exception->is_ambiguous()) {
                    // Rate limited: the request was not performed; one delayed retry with the same key.
                    sleep(1);
                }
            } catch (PlaidException $exception) {
                // Timeout or unreadable response: the refund may exist. Retrying with the same key is safe.
                $last_error = $exception;
            }
        }
        if (null === $refund && $last_error instanceof PlaidApiException && ! $last_error->is_ambiguous()) {
            // Still rate limited after the retry: Plaid did not perform either request.
            return $this->rejected($order, $record, $owner_token, $last_error);
        }
        if (null === $refund) {
            $refund = $this->find_unrecorded_refund($record);
        }
        if (null === $refund) {
            return $this->uncertain($order, $record, $owner_token, $last_error);
        }
        if (! $this->store->complete_creation($record->id, $owner_token, $refund->id, $refund->status, $refund->request_id)) {
            $fresh = $this->store->find($record->id);
            if (null === $fresh || $fresh->refund_id !== $refund->id) {
                // The reservation was changed by another worker; never report an unrecorded refund as done.
                $this->logger->log('error', 'refund_record_conflict', array('order_id' => $order->get_id(), 'refund_id' => $refund->id));
                $this->alerts->add($order, PaymentAlerts::REFUND_UNCERTAIN, 'record_conflict', (string) $record->id, $record->amount);
                return RefundOutcome::error(__('Plaid created the refund, but it could not be recorded. Do not refund again; see the Buckmerce alert on this order.', 'buckmerce-plaid'));
            }
        }
        $created = $this->store->find($record->id) ?? $record;
        $this->mark_wc_refund($wc_refund, $created->id, $refund->id, $refund->status);
        $order->add_order_note($this->created_note($order, $created, $refund, $reason));
        $this->logger->log('info', 'refund_created', array(
            'order_id' => $order->get_id(),
            'wc_refund_id' => $wc_refund->get_id(),
            'refund_id' => $refund->id,
            'transfer_id' => $record->transfer_id,
            'request_id' => $refund->request_id,
            'environment' => $record->environment,
        ));
        $this->project($order, $created, RefundState::CREATING, $refund->status, array('source' => 'create', 'failure_code' => $refund->failure_code));
        do_action('buckmerce_plaid_refund_created', $order, $refund->id, $record->amount);
        return RefundOutcome::success($created);
    }

    private function rejected(\WC_Order $order, RefundRecord $record, string $owner_token, PlaidApiException $exception): RefundOutcome
    {
        $this->store->finish_creation($record->id, $owner_token, RefundState::REJECTED, $exception->safe_code(), $exception->request_id());
        $this->store->schedule($record->id, null, null);
        $this->logger->log('warning', 'refund_rejected', array('order_id' => $order->get_id(), 'transfer_id' => $record->transfer_id, 'error_code' => $exception->safe_code(), 'request_id' => $exception->request_id()));
        $order->add_order_note(sprintf(
            /* translators: 1: refund amount, 2: Plaid error code */
            __('Buckmerce: Plaid rejected a refund of $%1$s (%2$s). No money was moved.', 'buckmerce-plaid'),
            $record->amount,
            strtoupper($exception->safe_code())
        ));
        return RefundOutcome::error(sprintf(
            /* translators: 1: Plaid error code, 2: Plaid error explanation */
            __('Plaid rejected the refund (%1$s). %2$s No money was moved.', 'buckmerce-plaid'),
            strtoupper($exception->safe_code()),
            self::explain_rejection($exception)
        ));
    }

    private function uncertain(\WC_Order $order, RefundRecord $record, string $owner_token, ?\Throwable $error): RefundOutcome
    {
        $code = $error instanceof PlaidException ? $error->safe_code() : 'unknown';
        $request_id = $error instanceof PlaidException ? $error->request_id() : '';
        $this->store->finish_creation($record->id, $owner_token, RefundState::UNCERTAIN, $code, $request_id);
        $plan = RefundMonitoringPolicy::plan(RefundState::UNCERTAIN, time());
        $this->store->schedule($record->id, $plan['next'], $plan['until']);
        $this->alerts->add($order, PaymentAlerts::REFUND_UNCERTAIN, $code, (string) $record->id, $record->amount);
        $this->logger->log('error', 'refund_uncertain', array('order_id' => $order->get_id(), 'transfer_id' => $record->transfer_id, 'error_code' => $code, 'request_id' => $request_id));
        $order->add_order_note(sprintf(
            /* translators: %s: refund amount */
            __('Buckmerce: Plaid did not confirm a refund of $%s (the request timed out or Plaid was unavailable). Buckmerce will check Plaid and record the refund automatically if it was created. Do not refund this order again until then.', 'buckmerce-plaid'),
            $record->amount
        ));
        return RefundOutcome::error(__('Plaid did not confirm the refund. Do not refund this order again: Buckmerce is checking with Plaid and will record the refund automatically if Plaid created it.', 'buckmerce-plaid'), $record);
    }

    /**
     * After an ambiguous create: a refund of the same amount on the same transfer that
     * Buckmerce has not recorded yet and that was created around the reservation time.
     */
    private function find_unrecorded_refund(RefundRecord $record): ?TransferRefund
    {
        try {
            $transfer = ($this->transfers)()->get($record->transfer_id);
        } catch (PlaidException) {
            return null;
        }
        $reserved = strtotime($record->created_at . ' UTC');
        $matches = array();
        foreach ($transfer->refunds as $refund) {
            if (! Money::same_amount($refund->amount, $record->amount) || null !== $this->store->find_by_refund_id($record->scope(), $refund->id)) {
                continue;
            }
            $created = '' === $refund->created ? false : strtotime($refund->created);
            if (false !== $reserved && false !== $created && $created < $reserved - self::ADOPTION_CLOCK_TOLERANCE_SECONDS) {
                continue;
            }
            $matches[] = $refund;
        }
        return 1 === count($matches) ? $matches[0] : null;
    }

    /**
     * Applies a Plaid refund status to a recorded refund (idempotent, out-of-order safe).
     *
     * @param array{source:string, failure_code?:string, event_id?:string} $context
     * @return string PaymentStateMachine decision vocabulary.
     */
    public function apply_status(RefundRecord $record, string $to, array $context): string
    {
        $decision = RefundStateMachine::decide($record->status, $to);
        if (PaymentStateMachine::APPLY === $decision) {
            if (! $this->store->transition($record->id, $record->status, $to, $context['failure_code'] ?? '', $context['event_id'] ?? '')) {
                // Another worker applied a change first: decide again against the fresh row.
                $fresh = $this->store->find($record->id);
                return null === $fresh ? PaymentStateMachine::CONFLICT : $this->apply_status($fresh, $to, $context);
            }
            $order = wc_get_order($record->order_id);
            $updated = $this->store->find($record->id) ?? $record;
            if ($order instanceof \WC_Order) {
                $this->project($order, $updated, $record->status, $to, $context);
            }
            return $decision;
        }
        if (PaymentStateMachine::CONFLICT === $decision) {
            $this->logger->log('warning', 'refund_state_conflict', array('order_id' => $record->order_id, 'refund_id' => $record->refund_id, 'from' => $record->status, 'to' => $to, 'source' => $context['source']));
        }
        $this->reschedule($record, $record->status);
        return $decision;
    }

    /**
     * Notes, WooCommerce refund meta, alerts and monitoring after a refund status change.
     *
     * @param array{source:string, failure_code?:string, event_id?:string} $context
     */
    private function project(\WC_Order $order, RefundRecord $record, string $from, string $to, array $context): void
    {
        $this->reschedule($record, $to);
        $wc_refund = $record->wc_refund_id > 0 ? wc_get_order($record->wc_refund_id) : null;
        if ($wc_refund instanceof \WC_Order_Refund) {
            $this->mark_wc_refund($wc_refund, $record->id, $record->refund_id, $to);
        }
        if (RefundState::CREATING === $from && RefundState::PENDING === $to) {
            return; // The creation note already says the refund was submitted.
        }
        $code = OrderPaymentProjector::code((string) ($context['failure_code'] ?? $record->failure_code));
        $label = sprintf('$%s (%s)', $record->amount, '' === $record->refund_id ? '—' : $record->refund_id);
        switch ($to) {
            case RefundState::POSTED:
                /* translators: %s: refund amount and Plaid refund ID */
                $order->add_order_note(sprintf(__('Buckmerce: refund %s was sent to the customer\'s bank.', 'buckmerce-plaid'), $label));
                break;
            case RefundState::SETTLED:
                /* translators: %s: refund amount and Plaid refund ID */
                $order->add_order_note(sprintf(__('Buckmerce: refund %s settled at the customer\'s bank.', 'buckmerce-plaid'), $label));
                break;
            case RefundState::FAILED:
            case RefundState::RETURNED:
                $returned = RefundState::RETURNED === $to;
                $order->add_order_note(sprintf(
                    /* translators: 1: refund amount and Plaid refund ID, 2: failure code */
                    $returned ? __('Buckmerce: REFUND RETURNED — refund %1$s was returned by the customer\'s bank (%2$s). The customer did not receive it; the money is back in your Plaid balance.', 'buckmerce-plaid') : __('Buckmerce: REFUND FAILED — refund %1$s failed at Plaid (%2$s). No money reached the customer. The WooCommerce refund record does not reflect a completed refund.', 'buckmerce-plaid'),
                    $label,
                    '' === $code ? '—' : $code
                ));
                $this->alerts->add($order, $returned ? PaymentAlerts::REFUND_RETURNED : PaymentAlerts::REFUND_FAILED, $code, (string) $record->id, $record->amount);
                $this->notifier->send(
                    $order,
                    sprintf(
                        /* translators: 1: order number */
                        $returned ? __('Refund returned for order #%1$s', 'buckmerce-plaid') : __('Refund failed for order #%1$s', 'buckmerce-plaid'),
                        $order->get_order_number()
                    ),
                    PaymentAlerts::message(array('type' => $returned ? PaymentAlerts::REFUND_RETURNED : PaymentAlerts::REFUND_FAILED, 'order_number' => (string) $order->get_order_number(), 'code' => $code, 'amount' => $record->amount))
                );
                if ($returned) {
                    do_action('buckmerce_plaid_refund_returned', $order, $record->refund_id, $code);
                } else {
                    do_action('buckmerce_plaid_refund_failed', $order, $record->refund_id, $code);
                }
                break;
            case RefundState::CANCELLED:
                /* translators: %s: refund amount and Plaid refund ID */
                $order->add_order_note(sprintf(__('Buckmerce: refund %s was cancelled before it was sent. The customer will not receive it.', 'buckmerce-plaid'), $label));
                $this->alerts->add($order, PaymentAlerts::REFUND_CANCELLED, $code, (string) $record->id, $record->amount);
                do_action('buckmerce_plaid_refund_cancelled', $order, $record->refund_id);
                break;
            case RefundState::VOID:
                $this->alerts->dismiss($order->get_id() . ':' . PaymentAlerts::REFUND_UNCERTAIN . ':' . $record->id);
                /* translators: %s: refund amount */
                $order->add_order_note(sprintf(__('Buckmerce: the unconfirmed refund of $%s was not created at Plaid. No money was moved; you can refund again.', 'buckmerce-plaid'), $record->amount));
                break;
        }
        if (RefundState::UNCERTAIN === $from && ! in_array($to, array(RefundState::UNCERTAIN, RefundState::VOID), true)) {
            $this->alerts->dismiss($order->get_id() . ':' . PaymentAlerts::REFUND_UNCERTAIN . ':' . $record->id);
        }
    }

    private function reschedule(RefundRecord $record, string $status): void
    {
        $until = '' === $record->monitor_until ? false : strtotime($record->monitor_until . ' UTC');
        $lease = '' === $record->lease_expires_at ? false : strtotime($record->lease_expires_at . ' UTC');
        $created = strtotime($record->created_at . ' UTC');
        $plan = RefundMonitoringPolicy::plan($status, time(), false === $until ? null : $until, false === $lease ? null : $lease, false === $created ? null : $created, $this->debit_horizon($record));
        $this->store->schedule($record->id, $plan['next'], $plan['until']);
    }

    /**
     * End of the refunded debit's monitoring: its unauthorized return window (+ buffer) from
     * Plaid, or the conservative fallback when Plaid reported none (MonitoringPolicy).
     */
    private function debit_horizon(RefundRecord $record): ?int
    {
        $order = wc_get_order($record->order_id);
        if (! $order instanceof \WC_Order) {
            return null;
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        $created = strtotime($record->created_at . ' UTC');
        if (null === $snapshot || $record->transfer_id !== (string) $order->get_meta(OrderMeta::TRANSFER_ID, true)) {
            // The refunded transfer is no longer the order's current one: fall back to the longest window.
            return false === $created ? null : $created + MonitoringPolicy::FALLBACK_UNAUTHORIZED_WINDOW_DAYS * DAY_IN_SECONDS;
        }
        $input = PaymentMonitor::input($order, (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true), $snapshot, time());
        return MonitoringPolicy::return_windows($input)[1];
    }

    /** Reconciliation / manual sync of one refund from Plaid. Safe to repeat. */
    public function sync(RefundRecord $record): void
    {
        if (! $record->scope()->equals($this->settings->account_scope())) {
            // Only the Plaid account that created a refund can read it.
            return;
        }
        if (RefundState::CREATING === $record->status && $record->lease_is_live()) {
            $this->reschedule($record, RefundState::CREATING);
            return;
        }
        if ('' === $record->refund_id) {
            $this->resolve_unconfirmed($record);
            return;
        }
        $refund = ($this->plaid_refunds)()->get($record->refund_id);
        if ($refund->transfer_id !== $record->transfer_id || ! Money::same_amount($refund->amount, $record->amount)) {
            $order = wc_get_order($record->order_id);
            if ($order instanceof \WC_Order) {
                $this->alerts->add($order, PaymentAlerts::REFUND_UNCERTAIN, 'refund_mismatch', (string) $record->id, $record->amount);
            }
            $this->logger->log('error', 'refund_mismatch', array('order_id' => $record->order_id, 'refund_id' => $record->refund_id));
            $this->store->schedule($record->id, null, null);
            return;
        }
        $this->apply_status($record, $refund->status, array('source' => 'sync', 'failure_code' => $refund->failure_code));
    }

    /**
     * An uncertain (or abandoned) create: adopt the refund if Plaid has it, void it once it
     * is certain that Plaid never created it. The create is never re-sent automatically.
     */
    private function resolve_unconfirmed(RefundRecord $record): void
    {
        if (RefundState::CREATING === $record->status) {
            $this->store->expire_abandoned();
            $record = $this->store->find($record->id) ?? $record;
        }
        if (RefundState::UNCERTAIN !== $record->status) {
            $this->reschedule($record, $record->status);
            return;
        }
        if ($record->age() < RefundMonitoringPolicy::UNCERTAIN_GRACE_SECONDS) {
            $this->reschedule($record, RefundState::UNCERTAIN);
            return;
        }
        $refund = $this->find_unrecorded_refund($record);
        if (null !== $refund) {
            $this->adopt($record, $refund, 'sync');
            return;
        }
        if ($record->age() >= RefundMonitoringPolicy::UNCERTAIN_VOID_AFTER_SECONDS) {
            $this->apply_status($record, RefundState::VOID, array('source' => 'sync'));
            return;
        }
        $this->reschedule($record, RefundState::UNCERTAIN);
    }

    /** Links a Plaid refund to an unconfirmed reservation and restores the WooCommerce refund record if needed. */
    public function adopt(RefundRecord $record, TransferRefund $refund, string $source): void
    {
        if (! $this->store->adopt($record->id, $record->status, $refund->id, $refund->status)) {
            return;
        }
        $order = wc_get_order($record->order_id);
        $adopted = $this->store->find($record->id) ?? $record;
        if (! $order instanceof \WC_Order) {
            return;
        }
        $wc_refund = $record->wc_refund_id > 0 ? wc_get_order($record->wc_refund_id) : null;
        if (! $wc_refund instanceof \WC_Order_Refund) {
            // WooCommerce discarded its refund record when the create looked failed; money did move.
            $created = wc_create_refund(array(
                'order_id' => $order->get_id(),
                'amount' => $record->amount,
                'reason' => __('Pay by Bank refund confirmed by Plaid after a timeout.', 'buckmerce-plaid'),
                'refund_payment' => false,
                'restock_items' => false,
            ));
            if ($created instanceof \WC_Order_Refund) {
                $this->store->link_wc_refund($record->id, $created->get_id());
                $adopted = $this->store->find($record->id) ?? $adopted;
                $wc_refund = $created;
            } else {
                $this->alerts->add($order, PaymentAlerts::EXTERNAL_REFUND, 'record_manually', (string) $record->id, $record->amount);
            }
        }
        if ($wc_refund instanceof \WC_Order_Refund) {
            $this->mark_wc_refund($wc_refund, $adopted->id, $refund->id, $refund->status);
        }
        $order->add_order_note(sprintf(
            /* translators: 1: refund amount, 2: Plaid refund ID */
            __('Buckmerce: Plaid confirmed the refund of $%1$s (refund ID %2$s) that had timed out. It is now recorded.', 'buckmerce-plaid'),
            $record->amount,
            $refund->id
        ));
        $this->logger->log('warning', 'refund_adopted', array('order_id' => $order->get_id(), 'refund_id' => $refund->id, 'source' => $source));
        $this->project($order, $adopted, RefundState::UNCERTAIN, $refund->status, array('source' => $source, 'failure_code' => $refund->failure_code));
    }

    /**
     * Records a refund that was created outside Buckmerce (e.g. the Plaid Dashboard), reported
     * by the event stream of $scope — the account that owns the refund.
     */
    public function record_external(\WC_Order $order, TransferRefund $refund, string $attempt_id, AccountScope $scope): ?RefundRecord
    {
        $record = $this->store->insert_external(array(
            'order_id' => $order->get_id(),
            'environment' => $scope->environment,
            'account_fp' => $scope->account_fp,
            'attempt_id' => $attempt_id,
            'transfer_id' => $refund->transfer_id,
            'refund_id' => $refund->id,
            'amount' => Money::transfer_amount($refund->amount),
            'currency' => 'USD',
            'status' => $refund->status,
            'failure_code' => $refund->failure_code,
        ));
        if (null === $record) {
            return $this->store->find_by_refund_id($scope, $refund->id);
        }
        $this->alerts->add($order, PaymentAlerts::EXTERNAL_REFUND, '', (string) $record->id, $record->amount);
        $order->add_order_note(sprintf(
            /* translators: 1: refund amount, 2: Plaid refund ID */
            __('Buckmerce: a refund of $%1$s (refund ID %2$s) was created outside WooCommerce, for example in the Plaid Dashboard. It counts toward the refundable amount. Record it in WooCommerce as a manual refund if it is not recorded yet.', 'buckmerce-plaid'),
            $record->amount,
            $refund->id
        ));
        $this->logger->log('warning', 'refund_external_recorded', array('order_id' => $order->get_id(), 'refund_id' => $refund->id, 'transfer_id' => $refund->transfer_id));
        $this->reschedule($record, $record->status);
        if (RefundState::is_failure($record->status)) {
            $this->alerts->add($order, RefundState::RETURNED === $record->status ? PaymentAlerts::REFUND_RETURNED : PaymentAlerts::REFUND_FAILED, $record->failure_code, (string) $record->id, $record->amount);
        }
        return $record;
    }

    /**
     * ReturnListener: the original debit was returned. Refunds that already left the merchant
     * are exposure (the customer may have been paid twice); refunds still pending are
     * cancelled while Plaid allows it, because the customer already got the money back.
     */
    public function on_payment_returned(\WC_Order $order, string $transfer_id): array
    {
        $account = (string) $order->get_meta(OrderMeta::ACCOUNT_FINGERPRINT, true);
        $scope = new AccountScope((string) $order->get_meta(OrderMeta::ENVIRONMENT, true), '' === $account ? $this->settings->account_fingerprint() : $account);
        $exposed = array();
        foreach ($this->store->for_transfer($scope, $transfer_id) as $record) {
            if (RefundState::PENDING === $record->status && '' !== $record->refund_id) {
                try {
                    ($this->plaid_refunds)()->cancel($record->refund_id);
                    $this->apply_status($record, RefundState::CANCELLED, array('source' => 'return_protection'));
                    continue;
                } catch (PlaidException $exception) {
                    $this->logger->log('warning', 'refund_cancel_failed', array('order_id' => $order->get_id(), 'refund_id' => $record->refund_id, 'error_code' => $exception->safe_code()));
                    $order->add_order_note(sprintf(
                        /* translators: 1: Plaid refund ID, 2: Plaid error code */
                        __('Buckmerce: the pending refund %1$s could not be cancelled after the payment was returned (%2$s).', 'buckmerce-plaid'),
                        $record->refund_id,
                        strtoupper($exception->safe_code())
                    ));
                }
            }
            $fresh = $this->store->find($record->id) ?? $record;
            if (RefundState::is_active($fresh->status)) {
                $exposed[] = $fresh->amount;
            }
        }
        try {
            $amount = Money::sum($exposed);
        } catch (PaymentException) {
            $amount = '0.00';
        }
        return array('count' => count($exposed), 'amount' => $amount);
    }

    /** Bounded reconciliation pass over due refunds. Returns how many were checked. */
    public function reconcile_due(int $limit): int
    {
        $this->store->expire_abandoned();
        $checked = 0;
        foreach ($this->store->due($this->settings->account_scope(), $limit) as $record) {
            try {
                $this->sync($record);
            } catch (\Throwable $exception) {
                $this->store->schedule($record->id, time() + 15 * MINUTE_IN_SECONDS, null);
                $this->logger->log('warning', 'refund_reconciliation_failed', array('order_id' => $record->order_id, 'refund_id' => $record->refund_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            }
            ++$checked;
        }
        return $checked;
    }

    /** Manual "Sync with Plaid": re-reads every refund of the order that can still change. */
    public function sync_order(\WC_Order $order): void
    {
        foreach ($this->store->for_order($order->get_id()) as $record) {
            if (! RefundState::is_terminal($record->status) || '' !== $record->reconcile_after) {
                $this->sync($record);
            }
        }
    }

    /** @return list<RefundRecord> */
    public function for_order(\WC_Order $order): array
    {
        return $this->store->for_order($order->get_id());
    }

    private function outcome_for(RefundRecord $record): RefundOutcome
    {
        return match (true) {
            RefundState::is_active($record->status) && RefundState::UNCERTAIN !== $record->status && RefundState::CREATING !== $record->status => RefundOutcome::success($record),
            RefundState::UNCERTAIN === $record->status, RefundState::CREATING === $record->status => RefundOutcome::error(__('This refund is still being confirmed with Plaid. Do not refund again.', 'buckmerce-plaid'), $record),
            default => RefundOutcome::error(__('This refund was not completed by Plaid. No money was moved for it.', 'buckmerce-plaid'), $record),
        };
    }

    private function mark_wc_refund(\WC_Order_Refund $wc_refund, int $row_id, string $refund_id, string $status): void
    {
        $wc_refund->update_meta_data('_bmfp_refund_row', (string) $row_id);
        if ('' !== $refund_id) {
            $wc_refund->update_meta_data('_bmfp_refund_id', $refund_id);
        }
        $wc_refund->update_meta_data('_bmfp_refund_status', $status);
        $wc_refund->save();
    }

    private function created_note(\WC_Order $order, RefundRecord $record, TransferRefund $refund, string $reason): string
    {
        $note = sprintf(
            /* translators: 1: refund amount, 2: Plaid refund ID */
            __('Buckmerce: refund of $%1$s submitted to Plaid (refund ID %2$s). The customer usually receives it within a few business days.', 'buckmerce-plaid'),
            $record->amount,
            $refund->id
        );
        if ('' !== trim($reason)) {
            $note .= ' ' . sprintf(/* translators: %s: refund reason */ __('Reason: %s', 'buckmerce-plaid'), OrderPaymentProjector::text($reason));
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        if (null !== $snapshot) {
            [, $unauthorized_end] = MonitoringPolicy::return_windows(PaymentMonitor::input($order, (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true), $snapshot, time()));
            if (time() < $unauthorized_end) {
                $note .= ' ' . sprintf(
                    /* translators: %s: date */
                    __('Warning: the original bank payment can still be returned by the customer\'s bank until about %s. If it is returned, you may lose both the payment and this refund; Buckmerce will alert you.', 'buckmerce-plaid'),
                    gmdate('Y-m-d', $unauthorized_end)
                );
            }
        }
        return $note;
    }

    private static function explain_rejection(PlaidApiException $exception): string
    {
        $message = OrderPaymentProjector::text($exception->getMessage());
        // "Plaid API error TYPE/CODE (HTTP n): message" → keep only Plaid's explanation.
        $message = preg_replace('/^Plaid API error [^:]+:\s*/', '', $message) ?? '';
        return '' === $message ? '' : rtrim($message, '.') . '.';
    }
}
