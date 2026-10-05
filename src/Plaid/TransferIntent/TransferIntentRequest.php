<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\TransferIntent;

use Buckmerce\Plaid\Exception\MissingAccountHolderNameException;
use Buckmerce\Plaid\Payment\PaymentSnapshot;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Builds the /transfer/intent/create request exclusively from server-side
 * data: the immutable snapshot, the WooCommerce order and merchant settings.
 */
final class TransferIntentRequest
{
    public const LEGAL_NAME_MAX = 100;

    /**
     * @param array{legal_name:string, email_address?:string} $user
     * @return array<string, mixed>
     */
    public static function build(PaymentSnapshot $snapshot, string $statement_descriptor, array $user, string $network, string $funding_account_id, string $site_marker = ''): array
    {
        $body = array(
            'mode' => 'PAYMENT',
            'amount' => $snapshot->amount,
            'iso_currency_code' => $snapshot->currency,
            'description' => self::description($statement_descriptor),
            'network' => $network,
            // Transfer UI is an Internet-authorized consumer debit: always WEB.
            'ach_class' => Settings::ACH_CLASS,
            'user' => $user,
            // Correlation only: ASCII strings, no secrets and no personal data.
            'metadata' => array(
                'bmfp_order_id' => (string) $snapshot->order_id,
                'bmfp_attempt_id' => $snapshot->attempt_id,
                'bmfp_environment' => $snapshot->environment,
            ),
        );
        if ('' !== $site_marker) {
            $body['metadata']['bmfp_site'] = $site_marker;
        }
        if ('' !== $funding_account_id) {
            // Only valid for accounts without Plaid Ledger.
            $body['funding_account_id'] = $funding_account_id;
        }
        return $body;
    }

    /**
     * Plaid requires 1–15 characters and recommends a stable, purpose-describing word that
     * fits the 10-character ACH limit; variable data such as order numbers belong in metadata
     * (https://plaid.com/docs/transfer/creating-transfers/#description-field-recommendations).
     */
    public static function description(string $statement_descriptor): string
    {
        $description = Settings::normalize_statement_descriptor($statement_descriptor);
        return '' === $description ? Settings::DEFAULT_STATEMENT_DESCRIPTOR : $description;
    }

    /**
     * Account holder details from the order billing data. The legal name is never invented:
     * without a billing first and last name the payment cannot start.
     *
     * @return array{legal_name:string, email_address?:string}
     * @throws MissingAccountHolderNameException
     */
    public static function user_from_order(\WC_Order $order): array
    {
        $name = self::legal_name((string) $order->get_billing_first_name(), (string) $order->get_billing_last_name());
        if ('' === $name) {
            throw new MissingAccountHolderNameException('The order has no billing first and last name for the account holder.');
        }
        $user = array('legal_name' => $name);
        $email = (string) $order->get_billing_email();
        if ('' !== $email && is_email($email)) {
            $user['email_address'] = $email;
        }
        // phone_number is intentionally omitted: Plaid validates it against real
        // numbering plans and malformed store data would block the payment.
        return $user;
    }

    /**
     * The individual's legal name: billing first + last name, both required. A company name
     * is never substituted, because Transfer UI authorizes a consumer (WEB) debit.
     */
    public static function legal_name(string $first_name, string $last_name): string
    {
        $first = self::clean_name($first_name);
        $last = self::clean_name($last_name);
        if ('' === $first || '' === $last) {
            return '';
        }
        return trim(mb_substr($first . ' ' . $last, 0, self::LEGAL_NAME_MAX));
    }

    private static function clean_name(string $value): string
    {
        // Control characters and markup never belong in a legal name.
        $value = preg_replace('/[\x00-\x1F\x7F<>]/u', '', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
