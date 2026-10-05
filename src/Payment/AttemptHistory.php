<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Settings\AccountScope;

/**
 * Durable history of an order's payment attempts.
 *
 * The current attempt lives in the order's _bmfp_* meta and the payment index. When a new
 * attempt replaces it (after a failure, a return or an unusable intent), the complete
 * attempt is archived here first: identifiers, amounts, provider timestamps and outcome.
 * Attempts that moved money (have a transfer) are never dropped; attempts without a
 * transfer (abandoned or failed authorizations) are capped to keep the order bounded.
 */
final class AttemptHistory
{
    public const MAX_WITHOUT_TRANSFER = 20;

    /** Per-attempt meta that must not leak into the next attempt. */
    public const ATTEMPT_KEYS = array(
        OrderMeta::PAYMENT_STATE,
        OrderMeta::PAYMENT_SNAPSHOT,
        OrderMeta::TRANSFER_INTENT_ID,
        OrderMeta::TRANSFER_INTENT_STATUS,
        OrderMeta::TRANSFER_ID,
        OrderMeta::TRANSFER_STATUS,
        OrderMeta::FAILURE_CODE,
        OrderMeta::RETURN_CODE,
        OrderMeta::FAILURE_DESCRIPTION,
        OrderMeta::LINK_TOKEN_EXPIRES_AT,
        OrderMeta::LAST_EVENT_ID,
        OrderMeta::RETURN_ALERTED,
        OrderMeta::TRANSFER_CREATED_AT,
        OrderMeta::SETTLED_AT,
        OrderMeta::FUNDS_AVAILABLE_AT,
        OrderMeta::RETURNED_AT,
        OrderMeta::STANDARD_RETURN_WINDOW,
        OrderMeta::UNAUTHORIZED_RETURN_WINDOW,
        OrderMeta::EXPECTED_FUNDS_AVAILABLE_DATE,
        OrderMeta::TRANSFER_CANCELLABLE,
        OrderMeta::MANUAL_REVIEW_REASON,
    );

    /** @return list<array<string, string>> Oldest first. */
    public static function all(\WC_Order $order): array
    {
        $stored = $order->get_meta(OrderMeta::RETIRED_ATTEMPTS, true);
        $history = array();
        foreach (is_array($stored) ? $stored : array() as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $clean = array();
            foreach ($entry as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $clean[$key] = (string) $value;
                }
            }
            if ('' === ($clean['attempt_id'] ?? '')) {
                // Entries written by 0.1.0 carry the attempt only inside the snapshot.
                $snapshot = PaymentSnapshot::from_json($clean['snapshot'] ?? '');
                if (null !== $snapshot) {
                    $clean += array('attempt_id' => $snapshot->attempt_id, 'amount' => $snapshot->amount, 'currency' => $snapshot->currency, 'environment' => $snapshot->environment, 'created_at' => $snapshot->created_at);
                }
            }
            $history[] = $clean;
        }
        return $history;
    }

    public static function is_retired(\WC_Order $order, string $attempt_id): bool
    {
        if ('' === $attempt_id) {
            return false;
        }
        foreach (self::all($order) as $entry) {
            if (hash_equals((string) ($entry['attempt_id'] ?? ''), $attempt_id)) {
                return true;
            }
        }
        return false;
    }

    /** The attempt is the order's current attempt or one of its archived attempts. */
    public static function belongs_to(\WC_Order $order, string $attempt_id): bool
    {
        $current = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        return (null !== $current && '' !== $attempt_id && hash_equals($current->attempt_id, $attempt_id)) || self::is_retired($order, $attempt_id);
    }

    /**
     * Whether the order's attempt (current or archived) was made with the given Plaid account
     * and environment. Attempts recorded before the account fingerprint existed have none and
     * are attributed to the configured account.
     */
    public static function attempt_in_scope(\WC_Order $order, string $attempt_id, AccountScope $scope): bool
    {
        $current = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        if (null !== $current && '' !== $attempt_id && hash_equals($current->attempt_id, $attempt_id)) {
            $environment = $current->environment;
            $account = (string) $order->get_meta(OrderMeta::ACCOUNT_FINGERPRINT, true);
        } else {
            $entry = null;
            foreach (self::all($order) as $candidate) {
                if ('' !== $attempt_id && hash_equals((string) ($candidate['attempt_id'] ?? ''), $attempt_id)) {
                    $entry = $candidate;
                }
            }
            if (null === $entry) {
                return false;
            }
            $environment = (string) ($entry['environment'] ?? '');
            $account = (string) ($entry['account_fp'] ?? '');
        }
        return $scope->environment === $environment && ('' === $account || hash_equals($scope->account_fp, $account));
    }

    public static function has_returned_attempt(\WC_Order $order): bool
    {
        foreach (self::all($order) as $entry) {
            if (PaymentState::RETURNED === ($entry['payment_state'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Archives the current attempt and clears its per-attempt meta (the caller saves).
     * Returns the archived entry.
     *
     * @return array<string, string>
     */
    public static function archive(\WC_Order $order, string $reason): array
    {
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        $meta = static fn (string $key): string => (string) $order->get_meta($key, true);
        $state = $meta(OrderMeta::PAYMENT_STATE);
        $paid = $order->get_date_paid('edit');
        $entry = array(
            'attempt_id' => null === $snapshot ? '' : $snapshot->attempt_id,
            'environment' => null === $snapshot ? $meta(OrderMeta::ENVIRONMENT) : $snapshot->environment,
            'account_fp' => $meta(OrderMeta::ACCOUNT_FINGERPRINT),
            'amount' => null === $snapshot ? '' : $snapshot->amount,
            'currency' => null === $snapshot ? '' : $snapshot->currency,
            'created_at' => null === $snapshot ? '' : $snapshot->created_at,
            'transfer_intent_id' => $meta(OrderMeta::TRANSFER_INTENT_ID),
            'transfer_id' => $meta(OrderMeta::TRANSFER_ID),
            'payment_state' => $state,
            'transfer_status' => $meta(OrderMeta::TRANSFER_STATUS),
            'failure_code' => $meta(OrderMeta::FAILURE_CODE),
            'return_code' => $meta(OrderMeta::RETURN_CODE),
            'failure_description' => $meta(OrderMeta::FAILURE_DESCRIPTION),
            'transfer_created_at' => $meta(OrderMeta::TRANSFER_CREATED_AT),
            'settled_at' => $meta(OrderMeta::SETTLED_AT),
            'funds_available_at' => $meta(OrderMeta::FUNDS_AVAILABLE_AT),
            'returned_at' => $meta(OrderMeta::RETURNED_AT),
            'paid_at' => null !== $paid && in_array($state, array(PaymentState::RETURNED, PaymentState::FUNDS_AVAILABLE, PaymentState::SETTLED), true) ? gmdate('c', $paid->getTimestamp()) : '',
            'unauthorized_return_window' => $meta(OrderMeta::UNAUTHORIZED_RETURN_WINDOW),
            'reason' => substr(preg_replace('/[^a-z0-9_]/', '', $reason) ?? '', 0, 64),
            'retired_at' => gmdate('c'),
            // Kept for readers of the 0.1.0 format.
            'snapshot' => $meta(OrderMeta::PAYMENT_SNAPSHOT),
        );
        $history = self::all($order);
        $history[] = $entry;
        $order->update_meta_data(OrderMeta::RETIRED_ATTEMPTS, self::retain($history));
        foreach (self::ATTEMPT_KEYS as $key) {
            $order->delete_meta_data($key);
        }
        return $entry;
    }

    /**
     * Money-moving attempts are always kept; only the oldest attempts without a transfer are dropped.
     *
     * @param list<array<string, string>> $history
     * @return list<array<string, string>>
     */
    public static function retain(array $history): array
    {
        $without_transfer = count(array_filter($history, static fn (array $entry): bool => '' === ($entry['transfer_id'] ?? '')));
        $drop = max(0, $without_transfer - self::MAX_WITHOUT_TRANSFER);
        $kept = array();
        foreach ($history as $entry) {
            if ($drop > 0 && '' === ($entry['transfer_id'] ?? '')) {
                --$drop;
                continue;
            }
            $kept[] = $entry;
        }
        return $kept;
    }
}
