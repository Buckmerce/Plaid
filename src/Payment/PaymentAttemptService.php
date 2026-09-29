<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Exception\PaymentAttemptBusyException;
use PayBridge\Plaid\Exception\PaymentException;
use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Persistence\DatabaseMutex;
use PayBridge\Plaid\Persistence\PaymentLockStatus;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Persistence\PaymentReservation;
use PayBridge\Plaid\Plaid\DTO\LinkToken;
use PayBridge\Plaid\Plaid\DTO\TransferIntent;
use PayBridge\Plaid\Plaid\Exception\PlaidException;
use PayBridge\Plaid\Plaid\Link\LinkTokenService;
use PayBridge\Plaid\Plaid\TransferIntent\TransferIntentRequest;
use PayBridge\Plaid\Plaid\TransferIntent\TransferIntentService;
use PayBridge\Plaid\Settings\Settings;
use PayBridge\Plaid\Support\Money;

/**
 * Creates, reuses or safely replaces the single active Plaid Transfer Intent
 * of a WooCommerce order and issues Link tokens bound to it.
 *
 * Duplicate-transfer safety (docs/CONCURRENCY_IDEMPOTENCY.md, ADR-0003, ADR-0007):
 * - all work for one order runs under a connection-bound DatabaseMutex;
 * - intent creation is fenced by a durable reservation row (owner token + lease);
 * - money can only move for an intent that received a Link token, and Link
 *   tokens are only issued for the one intent stored on the order;
 * - an intent is replaced only when Plaid reports it FAILED, its transfer ended
 *   without funds, or no Link token issued for it can still be used.
 */
final class PaymentAttemptService
{
    /** Extra time after the last Link token expiry before an intent may be retired. */
    public const AUTHORIZATION_GRACE_SECONDS = 3600;

    public const OUTCOME_READY = 'ready';
    public const OUTCOME_TRANSFER = 'transfer';

    private const REUSE = 'reuse';
    private const REPLACE = 'replace';

    public function __construct(
        private readonly Settings $settings,
        private readonly TransferIntentService $intents,
        private readonly LinkTokenService $link_tokens,
        private readonly TransferBinder $binder,
        private readonly OrderPaymentProjector $projector,
        private readonly PaymentLockStore $locks,
        private readonly Logger $logger
    ) {
    }

    /**
     * Called from process_payment(). Ensures the order has a usable intent.
     *
     * @return string OUTCOME_READY (show payment page) or OUTCOME_TRANSFER (a transfer already exists).
     * @throws PaymentException
     */
    public function start(\WC_Order $order): string
    {
        return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function (DatabaseMutex $mutex) use ($order): string {
            return $this->ensure_active_intent($this->reload($order->get_id()), $mutex);
        });
    }

    /**
     * Issues a Link token for the stored intent. The authorization window is
     * persisted before the token is returned, so a token that could not be
     * recorded is never handed to the browser.
     *
     * @return LinkToken|null null when a transfer already exists for the order.
     * @throws PaymentException
     */
    public function issue_link_token(\WC_Order $order): ?LinkToken
    {
        return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function (DatabaseMutex $mutex) use ($order): ?LinkToken {
            $order = $this->reload($order->get_id());
            if (self::OUTCOME_TRANSFER === $this->ensure_active_intent($order, $mutex)) {
                return null;
            }
            $order = $this->reload($order->get_id());
            $intent_id = (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
            if ('' === $intent_id) {
                throw new PaymentException('No active Transfer Intent is available.');
            }
            $mutex->assert_owned();
            try {
                $token = $this->link_tokens->create_for_intent(
                    $intent_id,
                    $this->client_user_id($order),
                    (string) get_bloginfo('name'),
                    LinkTokenService::language_from_locale((string) get_locale()),
                    $this->settings->link_customization_name()
                );
            } catch (PlaidException $exception) {
                throw new PaymentException('Plaid Link could not be started.', 0, $exception);
            }
            $expires = strtotime($token->expiration);
            if (false === $expires) {
                throw new PaymentException('Plaid returned an invalid Link token expiration.');
            }
            $previous = strtotime((string) $order->get_meta(OrderMeta::LINK_TOKEN_EXPIRES_AT, true));
            $window = gmdate('c', max($expires, false === $previous ? 0 : $previous));
            $order->update_meta_data(OrderMeta::LINK_TOKEN_EXPIRES_AT, $window);
            $order->update_meta_data(OrderMeta::REQUEST_ID, $token->request_id);
            // A token whose authorization window is not durable is never returned to the browser.
            OrderPersistence::save($order, array(OrderMeta::LINK_TOKEN_EXPIRES_AT => $window, OrderMeta::TRANSFER_INTENT_ID => $intent_id));
            $saved = $this->reload($order->get_id());
            if (PaymentState::INTENT_CREATED === (string) $saved->get_meta(OrderMeta::PAYMENT_STATE, true)) {
                $this->projector->transition($saved, PaymentState::INTENT_PENDING, array('source' => 'link_token'));
            }
            $this->logger->log('info', 'link_token_issued', array('order_id' => $order->get_id(), 'transfer_intent_id' => $intent_id, 'request_id' => $token->request_id));
            return $token;
        });
    }

    /** Whether any Link token issued for the active intent may still be used. */
    public static function authorization_window_open(\WC_Order $order, ?int $now = null): bool
    {
        $expires = strtotime((string) $order->get_meta(OrderMeta::LINK_TOKEN_EXPIRES_AT, true));
        if (false === $expires || $expires <= 0) {
            return false;
        }
        return $expires + self::AUTHORIZATION_GRACE_SECONDS > ($now ?? time());
    }

    /** @throws PaymentException */
    private function ensure_active_intent(\WC_Order $order, DatabaseMutex $mutex): string
    {
        if (Settings::GATEWAY_ID !== $order->get_payment_method()) {
            throw new PaymentException('The order does not use PayBridge.');
        }
        $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (PaymentState::MANUAL_REVIEW === $state) {
            throw new PaymentException('This payment requires merchant review before it can continue.');
        }
        if (PaymentState::has_transfer($state) && ! PaymentState::is_terminal($state)) {
            return self::OUTCOME_TRANSFER;
        }
        if (! $order->needs_payment()) {
            throw new PaymentException('This order cannot be paid.');
        }
        $environment = $this->settings->environment_name();
        $currency = strtoupper((string) $order->get_currency());
        if (Money::SUPPORTED_CURRENCY !== $currency) {
            throw new PaymentException('Only USD orders can be paid by bank.');
        }
        $amount = Money::transfer_amount((string) $order->get_total('edit'));

        $intent_id = (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
        if ('' === $intent_id) {
            $intent_id = $this->recover_intent_id($order);
        }
        if ('' !== $intent_id) {
            $decision = $this->evaluate_existing($order, $intent_id, $amount, $currency, $environment);
            if (self::REUSE === $decision) {
                return self::OUTCOME_READY;
            }
            if (self::OUTCOME_TRANSFER === $decision) {
                return self::OUTCOME_TRANSFER;
            }
            $order = $this->reload($order->get_id());
        }
        $this->create_intent($order, $mutex, $environment, $amount, $currency);
        return self::OUTCOME_READY;
    }

    /**
     * A crash after the lock row recorded the intent but before order meta was
     * saved leaves the intent ID only in the reservation row. Adopt it when the
     * stored snapshot is exactly the one the reservation was created for.
     */
    private function recover_intent_id(\WC_Order $order): string
    {
        $row = $this->locks->row($order->get_id());
        if (null === $row || PaymentLockStatus::CREATED !== $row['status'] || '' === $row['transfer_intent_id']) {
            return '';
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        if (null === $snapshot || ! hash_equals($row['snapshot_hash'], $snapshot->fingerprint())) {
            $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'recovery', 'reason' => 'reservation_snapshot_mismatch'));
            throw new PaymentException('The saved payment attempt is inconsistent and requires merchant review.');
        }
        $order->update_meta_data(OrderMeta::TRANSFER_INTENT_ID, $row['transfer_intent_id']);
        OrderPersistence::save($order, array(OrderMeta::TRANSFER_INTENT_ID => $row['transfer_intent_id']));
        if (PaymentState::INTENT_CREATING === (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true) || PaymentState::INTENT_UNCERTAIN === (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true)) {
            $this->projector->transition($order, PaymentState::INTENT_CREATED, array('source' => 'recovery'));
        }
        $this->logger->log('warning', 'transfer_intent_recovered', array('order_id' => $order->get_id(), 'transfer_intent_id' => $row['transfer_intent_id']));
        return $row['transfer_intent_id'];
    }

    /** @return string REUSE, REPLACE or OUTCOME_TRANSFER */
    private function evaluate_existing(\WC_Order $order, string $intent_id, string $amount, string $currency, string $environment): string
    {
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        if (null === $snapshot) {
            $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'checkout', 'reason' => 'missing_snapshot'));
            throw new PaymentException('The saved payment attempt is incomplete and requires merchant review.');
        }
        if ($snapshot->environment !== $environment) {
            // Never query one environment's objects with another environment's credentials.
            if (self::authorization_window_open($order)) {
                throw new PaymentException('A bank payment started in the other Plaid environment is still open. Please retry later.');
            }
            $this->retire($order, $intent_id, 'environment_changed');
            return self::REPLACE;
        }
        try {
            $intent = $this->intents->get($intent_id);
        } catch (PlaidException $exception) {
            // Without authoritative state never create another intent.
            throw new PaymentException('The bank payment status could not be verified. Please retry shortly.', 0, $exception);
        }
        if ($intent->id !== $intent_id || ! TransferBinder::intent_matches_snapshot($intent, $snapshot)) {
            $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'checkout', 'reason' => 'intent_amount_mismatch'));
            throw new PaymentException('The bank payment does not match the order and requires merchant review.');
        }
        $order->update_meta_data(OrderMeta::TRANSFER_INTENT_STATUS, $intent->status);
        $order->update_meta_data(OrderMeta::REQUEST_ID, $intent->request_id);
        $order->save();

        $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (TransferIntent::SUCCEEDED === $intent->status) {
            if (PaymentState::is_terminal($state)) {
                // The previous transfer failed, was cancelled or returned: a new attempt may start.
                $this->retire($order, $intent_id, 'transfer_' . $state);
                return self::REPLACE;
            }
            $this->binder->bind($order, $intent, 'checkout');
            return self::OUTCOME_TRANSFER;
        }
        if (TransferIntent::FAILED === $intent->status) {
            $this->projector->transition($order, PaymentState::INTENT_FAILED, array('source' => 'checkout', 'failure_code' => $intent->failure_code));
            $this->retire($this->reload($order->get_id()), $intent_id, 'intent_failed');
            return self::REPLACE;
        }
        if ($snapshot->matches($order->get_id(), $amount, $currency, $environment)) {
            return self::REUSE;
        }
        if (self::authorization_window_open($order)) {
            throw new PaymentException('The order changed while a bank payment authorization is still open. Please retry later.');
        }
        $this->retire($order, $intent_id, 'order_changed');
        return self::REPLACE;
    }

    /** @throws PaymentException */
    private function create_intent(\WC_Order $order, DatabaseMutex $mutex, string $environment, string $amount, string $currency): void
    {
        $snapshot = PaymentSnapshot::create($order->get_id(), $amount, $currency, $environment);
        $reservation = $this->locks->acquire($order->get_id(), $environment, $snapshot->attempt_id);
        if (PaymentReservation::ERROR === $reservation->status) {
            throw new PersistenceException('The payment reservation could not be created.');
        }
        if (PaymentReservation::BUSY === $reservation->status) {
            throw new PaymentAttemptBusyException('A bank payment for this order is already being prepared. Please retry shortly.');
        }
        if (PaymentReservation::ACTIVE_INTENT === $reservation->status) {
            $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'checkout', 'reason' => 'orphaned_reservation'));
            throw new PaymentException('An unrecorded bank payment exists for this order and requires merchant review.');
        }
        $token = $reservation->owner_token;

        $from = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (PaymentState::INTENT_CREATING === $from) {
            // The reservation was reclaimable, so the previous creator is gone and its
            // outcome is unknown. Record that before starting a new attempt (ADR-0007).
            $this->projector->transition($order, PaymentState::INTENT_UNCERTAIN, array('source' => 'checkout', 'failure_code' => 'abandoned_attempt'));
            $order = $this->reload($order->get_id());
            $from = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        }
        if (PaymentStateMachine::APPLY !== PaymentStateMachine::decide($from, PaymentState::INTENT_CREATING)) {
            $this->locks->mark_failed($order->get_id(), $token, 'invalid_state');
            throw new PaymentException('The payment is not in a state that allows a new bank payment.');
        }
        try {
            $order->update_meta_data(OrderMeta::PAYMENT_STATE, PaymentState::INTENT_CREATING);
            $order->update_meta_data(OrderMeta::PAYMENT_SNAPSHOT, $snapshot->to_json());
            $order->update_meta_data(OrderMeta::ENVIRONMENT, $environment);
            foreach (array(OrderMeta::TRANSFER_INTENT_ID, OrderMeta::TRANSFER_INTENT_STATUS, OrderMeta::TRANSFER_ID, OrderMeta::TRANSFER_STATUS, OrderMeta::FAILURE_CODE, OrderMeta::RETURN_CODE, OrderMeta::LINK_TOKEN_EXPIRES_AT, OrderMeta::LAST_EVENT_ID) as $key) {
                $order->delete_meta_data($key);
            }
            // The snapshot must be durable before any remote side effect.
            OrderPersistence::save($order, array(
                OrderMeta::PAYMENT_STATE => PaymentState::INTENT_CREATING,
                OrderMeta::PAYMENT_SNAPSHOT => $snapshot->to_json(),
                OrderMeta::TRANSFER_INTENT_ID => null,
            ));
        } catch (\Throwable $exception) {
            $this->locks->mark_failed($order->get_id(), $token, 'order_save_failed');
            throw new PersistenceException('The payment attempt could not be saved.', 0, $exception);
        }

        // Last local checks before the remote side effect; any failure here fails closed.
        try {
            $mutex->assert_owned();
            if (! $this->locks->begin_creation($order->get_id(), $token, $snapshot->fingerprint())) {
                throw new PersistenceException('The payment reservation changed before creation.');
            }
            $mutex->assert_owned();
        } catch (\Throwable $exception) {
            $this->locks->mark_failed($order->get_id(), $token, 'reservation_lost');
            $this->record_state($order->get_id(), PaymentState::INTENT_FAILED, 'reservation_lost');
            throw new PaymentException('The payment could not be reserved safely. Please retry.', 0, $exception);
        }

        try {
            $request = TransferIntentRequest::build(
                $snapshot,
                (string) $order->get_order_number(),
                TransferIntentRequest::user_from_order($order),
                $this->settings->network(),
                $this->settings->ach_class(),
                $this->settings->funding_account_id()
            );
            $intent = $this->intents->create($request);
        } catch (PlaidException $exception) {
            if ($exception->is_ambiguous()) {
                $this->locks->mark_uncertain($order->get_id(), $token, $exception->safe_code());
                $this->record_state($order->get_id(), PaymentState::INTENT_UNCERTAIN, $exception->safe_code());
            } else {
                $this->locks->mark_failed($order->get_id(), $token, $exception->safe_code());
                $this->record_state($order->get_id(), PaymentState::INTENT_FAILED, $exception->safe_code());
            }
            $this->logger->log('error', 'transfer_intent_create_failed', array(
                'order_id' => $order->get_id(),
                'request_id' => $exception->request_id(),
                'error_code' => $exception->safe_code(),
                'ambiguous' => $exception->is_ambiguous(),
            ));
            throw new PaymentException('The bank payment could not be started.', 0, $exception);
        } catch (ConfigurationException $exception) {
            $this->locks->mark_failed($order->get_id(), $token, 'configuration');
            $this->record_state($order->get_id(), PaymentState::INTENT_FAILED, 'configuration');
            throw $exception;
        }

        if (TransferIntent::PENDING !== $intent->status || ! TransferBinder::intent_matches_snapshot($intent, $snapshot)) {
            // Never persist (and therefore never authorize) an intent that does not match the order.
            $this->locks->mark_uncertain($order->get_id(), $token, 'intent_mismatch');
            $this->projector->transition($this->reload($order->get_id()), PaymentState::MANUAL_REVIEW, array('source' => 'checkout', 'reason' => 'intent_mismatch'));
            throw new PaymentException('Plaid returned a Transfer Intent that does not match the order.');
        }
        if (! $this->locks->mark_created($order->get_id(), $token, $intent->id)) {
            // Another worker owns the reservation now; this intent stays unused and can never be authorized.
            $this->logger->log('warning', 'transfer_intent_orphaned', array('order_id' => $order->get_id(), 'transfer_intent_id' => $intent->id, 'request_id' => $intent->request_id));
            throw new PaymentAttemptBusyException('The payment reservation was taken over by another request. Please retry.');
        }
        try {
            $order = $this->reload($order->get_id());
            $order->update_meta_data(OrderMeta::TRANSFER_INTENT_ID, $intent->id);
            $order->update_meta_data(OrderMeta::TRANSFER_INTENT_STATUS, $intent->status);
            $order->update_meta_data(OrderMeta::REQUEST_ID, $intent->request_id);
            $order->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c'));
            OrderPersistence::save($order, array(OrderMeta::TRANSFER_INTENT_ID => $intent->id));
            $this->projector->transition($order, PaymentState::INTENT_CREATED, array('source' => 'checkout'));
        } catch (\Throwable $exception) {
            // The intent ID is durable in the reservation row and will be adopted by the next request.
            throw new PaymentException('The bank payment was prepared but could not be saved. Please retry.', 0, $exception);
        }
        $this->logger->log('info', 'transfer_intent_created', array('order_id' => $order->get_id(), 'transfer_intent_id' => $intent->id, 'request_id' => $intent->request_id, 'environment' => $environment));
        do_action('paybridge_plaid_transfer_intent_created', $order, $intent->id);
    }

    /** Archives the active attempt and releases the reservation for exactly one new attempt. */
    private function retire(\WC_Order $order, string $intent_id, string $reason): void
    {
        if (! $this->locks->retire($order->get_id(), $intent_id)) {
            throw new PersistenceException('The previous payment attempt could not be retired.');
        }
        $retired = $order->get_meta(OrderMeta::RETIRED_ATTEMPTS, true);
        $retired = is_array($retired) ? $retired : array();
        $retired[] = array(
            'transfer_intent_id' => $intent_id,
            'transfer_id' => (string) $order->get_meta(OrderMeta::TRANSFER_ID, true),
            'payment_state' => (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true),
            'snapshot' => (string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true),
            'failure_code' => (string) $order->get_meta(OrderMeta::FAILURE_CODE, true),
            'return_code' => (string) $order->get_meta(OrderMeta::RETURN_CODE, true),
            'reason' => $reason,
            'retired_at' => gmdate('c'),
        );
        $order->update_meta_data(OrderMeta::RETIRED_ATTEMPTS, array_slice($retired, -20));
        foreach (array(OrderMeta::PAYMENT_STATE, OrderMeta::PAYMENT_SNAPSHOT, OrderMeta::TRANSFER_INTENT_ID, OrderMeta::TRANSFER_INTENT_STATUS, OrderMeta::TRANSFER_ID, OrderMeta::TRANSFER_STATUS, OrderMeta::LINK_TOKEN_EXPIRES_AT, OrderMeta::LAST_EVENT_ID, '_pbfp_return_alerted') as $key) {
            $order->delete_meta_data($key);
        }
        OrderPersistence::save($order, array(OrderMeta::PAYMENT_STATE => null, OrderMeta::TRANSFER_INTENT_ID => null, OrderMeta::TRANSFER_ID => null));
        $order->add_order_note(sprintf(
            /* translators: 1: Plaid Transfer Intent ID, 2: reason code */
            __('PayBridge: previous bank payment attempt retired (intent %1$s, %2$s). A new attempt may start.', 'paybridge-for-plaid'),
            $intent_id,
            $reason
        ));
        $this->logger->log('info', 'payment_attempt_retired', array('order_id' => $order->get_id(), 'transfer_intent_id' => $intent_id, 'reason' => $reason));
    }

    private function record_state(int $order_id, string $state, string $code): void
    {
        try {
            $this->projector->transition($this->reload($order_id), $state, array('source' => 'checkout', 'failure_code' => $code));
        } catch (\Throwable $exception) {
            // The reservation row remains the authoritative fail-closed record.
            $this->logger->log('error', 'payment_state_record_failed', array('order_id' => $order_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
        }
    }

    /** Stable, non-PII Link user identifier. */
    private function client_user_id(\WC_Order $order): string
    {
        return 'pbfp-' . substr(hash_hmac('sha256', 'order:' . $order->get_id() . ':' . $order->get_customer_id(), wp_salt('auth')), 0, 40);
    }

    private function reload(int $order_id): \WC_Order
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            throw new PaymentException('The order could not be loaded.');
        }
        return $order;
    }
}
