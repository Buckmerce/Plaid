<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

/**
 * Whether a new bank debit may be originated for an order whose transfer was returned.
 *
 * Plaid (verified 2026-10-01): "If reprocessing a returned
 * transfer, the description field [of /transfer/create] must be "Retry 1" or "Retry 2". You may
 * retry a transfer up to 2 times, within 180 days of creating the original transfer. Only
 * transfers that were returned with code R01 or R09 may be retried." R10 must never be
 * resubmitted. Plaid documents this only for /transfer/create; Transfer UI
 * (/transfer/intent/create), which Buckmerce 1.0 uses, has no documented way to mark a debit as
 * a retry of a returned transfer. Buckmerce therefore evaluates Plaid's rules completely and, for
 * the Transfer UI flow, never allows a same-order bank debit after a return: an eligible return
 * is BLOCK_UNSUPPORTED_FLOW, everything else is blocked by the rule it breaks.
 *
 * A normal failure before money moved (intent declined or failed, transfer failed or
 * cancelled) is not a return: the policy does not apply and the customer may try again.
 */
final class ReturnRetryPolicy
{
    /** Return codes Plaid allows to be reprocessed. Every other code, and an unknown code, is blocked. */
    public const RETRYABLE_RETURN_CODES = array('R01', 'R09');
    public const MAX_RETRIES = 2;
    public const RETRY_WINDOW_DAYS = 180;

    /** /transfer/intent/create + Transfer UI: no documented retry semantics (Buckmerce 1.0). */
    public const FLOW_TRANSFER_UI = 'transfer_ui';
    /** /transfer/create with description "Retry 1"/"Retry 2": the only documented retry flow. */
    public const FLOW_TRANSFER_CREATE = 'transfer_create';

    /**
     * Pure decision. $lineage lists the order's money-moving attempts oldest first; each entry
     * has the transfer ID, the Buckmerce payment state, the return code and the transfer's
     * creation time (ISO-8601, '' when unknown).
     *
     * @param list<array{transfer_id:string, state:string, return_code:string, created_at:string}> $lineage
     */
    public static function decide(array $lineage, string $flow, int $now): ReturnRetryDecision
    {
        $first_return = null;
        foreach ($lineage as $index => $entry) {
            if (PaymentState::RETURNED === $entry['state']) {
                $first_return = $index;
                break;
            }
        }
        if (null === $first_return) {
            return new ReturnRetryDecision(ReturnRetryDecision::NOT_RETURNED);
        }
        $original = $lineage[$first_return];
        $later = array_slice($lineage, $first_return + 1);
        // Every transfer created after the original return counts as a retry of it (conservative:
        // it includes repayments recorded before this policy existed).
        $retries_used = count($later);
        $latest_code = '';
        foreach (array_merge(array($original), $later) as $entry) {
            if (PaymentState::RETURNED !== $entry['state']) {
                continue;
            }
            $code = strtoupper(trim($entry['return_code']));
            $latest_code = $code;
            if (! in_array($code, self::RETRYABLE_RETURN_CODES, true)) {
                return new ReturnRetryDecision(ReturnRetryDecision::BLOCK_RETURN_CODE, '' === $code ? 'UNKNOWN' : $code, $original['transfer_id'], $retries_used);
            }
        }
        $created = '' === $original['created_at'] ? false : strtotime($original['created_at']);
        $window_ends_at = false === $created ? null : $created + self::RETRY_WINDOW_DAYS * DAY_IN_SECONDS;
        if ($retries_used >= self::MAX_RETRIES) {
            return new ReturnRetryDecision(ReturnRetryDecision::BLOCK_RETRY_LIMIT, $latest_code, $original['transfer_id'], $retries_used, $window_ends_at);
        }
        if (null === $window_ends_at || $now > $window_ends_at) {
            // An original transfer of unknown age cannot be proven to be inside the window.
            return new ReturnRetryDecision(ReturnRetryDecision::BLOCK_WINDOW_EXPIRED, $latest_code, $original['transfer_id'], $retries_used, $window_ends_at);
        }
        if (self::FLOW_TRANSFER_CREATE !== $flow) {
            return new ReturnRetryDecision(ReturnRetryDecision::BLOCK_UNSUPPORTED_FLOW, $latest_code, $original['transfer_id'], $retries_used, $window_ends_at);
        }
        return new ReturnRetryDecision(0 === $retries_used ? ReturnRetryDecision::ALLOW_RETRY_1 : ReturnRetryDecision::ALLOW_RETRY_2, $latest_code, $original['transfer_id'], $retries_used, $window_ends_at);
    }

    /** Buckmerce 1.0 originates every debit through Transfer UI. */
    public static function for_order(\WC_Order $order, ?int $now = null): ReturnRetryDecision
    {
        return self::decide(self::lineage($order), self::FLOW_TRANSFER_UI, $now ?? time());
    }

    /**
     * The order's attempts that created a transfer (archived attempts first, then the current
     * one), oldest first. Archived attempts that moved money are never dropped, so
     * the original returned transfer is always part of the lineage.
     *
     * @return list<array{transfer_id:string, state:string, return_code:string, created_at:string}>
     */
    public static function lineage(\WC_Order $order): array
    {
        $lineage = array();
        foreach (AttemptHistory::all($order) as $entry) {
            if ('' === ($entry['transfer_id'] ?? '')) {
                continue;
            }
            $lineage[] = array(
                'transfer_id' => (string) $entry['transfer_id'],
                'state' => (string) ($entry['payment_state'] ?? ''),
                'return_code' => (string) ($entry['return_code'] ?? ''),
                'created_at' => (string) ('' !== ($entry['transfer_created_at'] ?? '') ? $entry['transfer_created_at'] : ($entry['created_at'] ?? '')),
            );
        }
        $transfer_id = (string) $order->get_meta(OrderMeta::TRANSFER_ID, true);
        if ('' !== $transfer_id) {
            $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
            $created = (string) $order->get_meta(OrderMeta::TRANSFER_CREATED_AT, true);
            $lineage[] = array(
                'transfer_id' => $transfer_id,
                'state' => (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true),
                'return_code' => (string) $order->get_meta(OrderMeta::RETURN_CODE, true),
                'created_at' => '' !== $created ? $created : (null === $snapshot ? '' : $snapshot->created_at),
            );
        }
        return $lineage;
    }

    /** Merchant-facing explanation of a blocking decision (order panel, notes). */
    public static function merchant_explanation(ReturnRetryDecision $decision): string
    {
        return match ($decision->outcome) {
            ReturnRetryDecision::BLOCK_RETURN_CODE => sprintf(
                /* translators: %s: ACH return code such as R10 */
                __('Return code %s may not be debited again (Plaid allows retries only for R01 and R09; unauthorized returns such as R10 must never be resubmitted). Collect this payment another way after contacting the customer.', 'buckmerce-plaid'),
                $decision->return_code
            ),
            ReturnRetryDecision::BLOCK_RETRY_LIMIT => __('Plaid allows at most two retries of a returned transfer and they are used up. Collect this payment another way.', 'buckmerce-plaid'),
            ReturnRetryDecision::BLOCK_WINDOW_EXPIRED => __('Plaid allows retries only within 180 days of the original transfer. Collect this payment another way.', 'buckmerce-plaid'),
            ReturnRetryDecision::BLOCK_UNSUPPORTED_FLOW => sprintf(
                /* translators: %s: ACH return code R01 or R09 */
                __('Return code %s could be retried under Plaid\'s rules only as a marked retry ("Retry 1"/"Retry 2") of the original transfer, which Plaid Transfer UI does not support. Buckmerce therefore never debits this order again automatically. Contact the customer and collect the payment another way.', 'buckmerce-plaid'),
                $decision->return_code
            ),
            default => '',
        };
    }

    /** Customer-facing text on the order-pay page: no codes, no internals. */
    public static function customer_message(): string
    {
        return __('Your bank returned the earlier bank payment for this order, so Pay by Bank cannot be used to pay it again. Please choose another payment method or contact the store.', 'buckmerce-plaid');
    }
}
