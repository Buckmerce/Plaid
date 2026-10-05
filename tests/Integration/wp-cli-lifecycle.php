<?php

/**
 * v1 lifecycle guarantees against real WordPress/WooCommerce and the Plaid double:
 * maintenance independent of the enabled switch, return-window monitoring,
 * Plaid account/environment guard, legal name, no re-debit after a return
 * with attempt history, merchant cancel and manual-review actions,
 * Link customization in both environments.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Admin\ConfigurationStatus;
use Buckmerce\Plaid\Admin\DiagnosticsPage;
use Buckmerce\Plaid\Admin\SiteHealth;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Exception\ConfigurationException;
use Buckmerce\Plaid\Exception\MissingAccountHolderNameException;
use Buckmerce\Plaid\Payment\AttemptHistory;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentAttemptService;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Payment\ReturnRetryDecision;
use Buckmerce\Plaid\Payment\ReturnRetryPolicy;
use Buckmerce\Plaid\Exception\ReturnedPaymentRetryException;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Settings\Settings;

global $wpdb;
bmfp_configure();
bmfp_reset_world();
$mock = Buckmerce_Test_Plaid_Mock::class;

function bmfp_lc(): Container
{
    return new Container();
}

/** Checkout + Transfer UI + completion; returns the order and its transfer. */
function bmfp_lc_transfer(WC_Order $order, ?string $amount = null): string
{
    $result = bmfp_gateway()->process_payment($order->get_id());
    bmfp_assert('success' === $result['result'], 'Checkout for order ' . $order->get_id());
    $token = bmfp_lc()->attempts()->issue_link_token(bmfp_reload($order));
    bmfp_assert(null !== $token, 'Link token.');
    $transfer = Buckmerce_Test_Plaid_Mock::authorize($token->token, 'success', $amount);
    bmfp_lc()->completion()->complete(bmfp_reload($order));
    return $transfer;
}

function bmfp_lc_events(string $transfer, array $types): void
{
    foreach ($types as $type) {
        Buckmerce_Test_Plaid_Mock::add_event($transfer, $type);
    }
    bmfp_lc()->event_sync()->run();
}

function bmfp_lc_meta(WC_Order $order, string $key): string
{
    return (string) bmfp_reload($order)->get_meta($key, true);
}

// ---------------------------------------------------------------------------------
WP_CLI::log('Statement descriptor, WEB class and legal name reach Plaid exactly');
bmfp_configure(array('statement_descriptor' => 'MY SHOP'));
$described = bmfp_order('18.00');
bmfp_gateway()->process_payment($described->get_id());
$request = $mock::calls('/transfer/intent/create')[0]['body'];
bmfp_assert_same('MY SHOP', $request['description'], 'The merchant statement descriptor is the Plaid description.');
bmfp_assert_same('web', $request['ach_class'], 'Always WEB.');
bmfp_assert_same('Anne Charleston', $request['user']['legal_name'], 'Legal name from billing first + last name.');
bmfp_configure();

WP_CLI::log('Missing account holder names fail before anything is reserved');
$cases = array(
    'company only' => array('first' => '', 'last' => '', 'company' => 'Acme LLC'),
    'missing first name' => array('first' => '', 'last' => 'Charleston', 'company' => ''),
    'missing last name' => array('first' => 'Anne', 'last' => '', 'company' => ''),
    'blank names' => array('first' => '  ', 'last' => ' ', 'company' => ''),
);
foreach ($cases as $label => $case) {
    $creates = count($mock::calls('/transfer/intent/create'));
    $nameless = bmfp_order('19.00');
    $nameless->set_billing_first_name($case['first']);
    $nameless->set_billing_last_name($case['last']);
    $nameless->set_billing_company($case['company']);
    $nameless->save();
    wc_clear_notices();
    $result = bmfp_gateway()->process_payment($nameless->get_id());
    bmfp_assert_same('failure', $result['result'], 'Checkout refused: ' . $label);
    $notices = wc_get_notices('error');
    bmfp_assert(1 === count($notices) && str_contains((string) $notices[0]['notice'], 'legal first and last name'), 'Actionable notice: ' . $label);
    bmfp_assert_same($creates, count($mock::calls('/transfer/intent/create')), 'No Plaid call: ' . $label);
    bmfp_assert(null === bmfp_index_row($nameless), 'Nothing reserved: ' . $label);
    bmfp_assert_same('', bmfp_lc_meta($nameless, OrderMeta::PAYMENT_STATE), 'No payment state: ' . $label);
    try {
        bmfp_lc()->attempts()->issue_link_token(bmfp_reload($nameless));
        bmfp_assert(false, 'The payment page cannot start either: ' . $label);
    } catch (MissingAccountHolderNameException $exception) {
        bmfp_assert(true, 'ok');
    }
}
wc_clear_notices();
$customer = wp_insert_user(array('user_login' => 'bmfp_lc_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'lc' . wp_rand() . '@example.com'));
foreach (array('guest' => 0, 'logged-in' => (int) $customer) as $label => $customer_id) {
    wp_set_current_user($customer_id);
    $named = bmfp_order('19.50', $customer_id);
    bmfp_assert_same('success', bmfp_gateway()->process_payment($named->get_id())['result'], 'A named ' . $label . ' customer can pay.');
}
wp_set_current_user(0);
$_POST = array('billing_first_name' => 'Anne', 'billing_last_name' => '');
bmfp_assert(false === bmfp_gateway()->validate_fields(), 'Classic Checkout validates the legal name before creating the order.');
$_POST = array('billing_first_name' => 'Anne', 'billing_last_name' => 'Charleston');
bmfp_assert(true === bmfp_gateway()->validate_fields(), 'A complete name passes Classic validation.');
$_POST = array();
wc_clear_notices();

// ---------------------------------------------------------------------------------
WP_CLI::log('Disabling the gateway stops new payments, never the maintenance of existing ones');
bmfp_reset_world();
bmfp_configure();
$kept = bmfp_order('11.11');
$kept_transfer = bmfp_lc_transfer($kept);
bmfp_lc_events($kept_transfer, array('posted', 'settled', 'funds_available'));
bmfp_assert(bmfp_reload($kept)->is_paid(), 'Precondition: paid.');
$missed = bmfp_order('33.33');
$missed_transfer = bmfp_lc_transfer($missed);
$intent_only = bmfp_order('20.50');
bmfp_gateway()->process_payment($intent_only->get_id());
bmfp_configure(array('enabled' => 'no'));
bmfp_assert(Scheduler::maintenance_active(Settings::load()), 'Payments exist: maintenance stays active while disabled.');
as_unschedule_all_actions(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP);
( new Scheduler(bmfp_lc()) )->ensure_recurring();
bmfp_assert(as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP), 'Reconciliation stays scheduled while the gateway is disabled.');
bmfp_assert(! bmfp_gateway()->is_available(), 'New checkouts are blocked.');
$new = bmfp_order('20.00');
bmfp_assert_same('failure', bmfp_gateway()->process_payment($new->get_id())['result'], 'process_payment refuses new payments while disabled.');
bmfp_assert(null === bmfp_lc()->attempts()->issue_link_token(bmfp_reload($missed)), 'An order with a transfer is redirected, never re-authorized.');
try {
    bmfp_lc()->attempts()->issue_link_token(bmfp_reload($intent_only));
    bmfp_assert(false, 'No new Link session while disabled.');
} catch (ConfigurationException $exception) {
    bmfp_assert(true, 'ok');
}
// A return arrives while disabled: the webhook is accepted and the event processed.
$mock::add_event($kept_transfer, 'returned', 'R10');
$body = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'sandbox'));
$webhook = bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($body)), $body);
bmfp_assert_same(200, $webhook['status'], 'Verified webhooks are handled while disabled.');
bmfp_run_scheduled(Scheduler::EVENT_SYNC_HOOK);
bmfp_assert_same(PaymentState::RETURNED, bmfp_lc_meta($kept, OrderMeta::PAYMENT_STATE), 'Return processed while disabled.');
bmfp_assert_same('R10', bmfp_lc_meta($kept, OrderMeta::RETURN_CODE), 'Return code kept.');
// A missed webhook while disabled: reconciliation recovers the remote state.
$mock::advance($missed_transfer);
( new Scheduler(bmfp_lc()) )->run_reconciliation();
bmfp_assert_same(PaymentState::RETURNED, bmfp_lc_meta($missed, OrderMeta::PAYMENT_STATE), 'Reconciliation recovered the lifecycle of a payment while disabled.');
// Completion of an authorization that finished just before disabling still works.
bmfp_configure();
$late = bmfp_order('21.00');
bmfp_gateway()->process_payment($late->get_id());
$late_token = bmfp_lc()->attempts()->issue_link_token(bmfp_reload($late));
bmfp_configure(array('enabled' => 'no'));
$mock::authorize($late_token->token);
bmfp_assert_same('submitted', bmfp_lc()->completion()->complete(bmfp_reload($late))->status, 'Completion of an existing authorization is not blocked by disabling.');
// Without credentials nothing can be read: the scheduler stops and Site Health says why.
bmfp_configure(array('enabled' => 'no', 'secret' => ''));
( new Scheduler(bmfp_lc()) )->ensure_recurring();
bmfp_assert(! as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP), 'No credentials → nothing scheduled.');
$health = ( new SiteHealth() )->test_background();
bmfp_assert('critical' === $health['status'] && str_contains($health['description'], 'cannot be monitored'), 'Site Health flags unmonitored payments.');
bmfp_configure();

// ---------------------------------------------------------------------------------
WP_CLI::log('Monitoring follows the Plaid return window, not the order date');
$old = bmfp_order('15.00');
$old->set_date_created(time() - 2 * YEAR_IN_SECONDS);
$old->save();
$old_transfer = bmfp_lc_transfer($old);
bmfp_lc_events($old_transfer, array('posted', 'settled', 'funds_available'));
$row = bmfp_index_row($old);
bmfp_assert(PaymentState::FUNDS_AVAILABLE === $row['payment_state'] && null !== $row['reconcile_after'] && null !== $row['monitor_until'], 'A two-year-old order with a new transfer is monitored.');
bmfp_assert(strtotime($row['monitor_until'] . ' UTC') > time() + 80 * DAY_IN_SECONDS, 'Monitoring runs until the return window closes.');
// Reconciliation reads the provider windows and uses them.
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_payment_locks SET reconcile_after = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 1), $old->get_id()));
bmfp_lc()->reconciliation()->run();
$window = bmfp_lc_meta($old, OrderMeta::UNAUTHORIZED_RETURN_WINDOW);
bmfp_assert(1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $window) && '' !== bmfp_lc_meta($old, OrderMeta::STANDARD_RETURN_WINDOW), 'Plaid return windows persisted.');
$row = bmfp_index_row($old);
bmfp_assert_same(gmdate('Y-m-d', strtotime($window . 'T23:59:59Z') + 2 * DAY_IN_SECONDS), substr((string) $row['monitor_until'], 0, 10), 'monitor_until = unauthorized_return_window + buffer.');
bmfp_assert('' !== bmfp_lc_meta($old, OrderMeta::SETTLED_AT) && '' !== bmfp_lc_meta($old, OrderMeta::TRANSFER_CREATED_AT), 'Settlement and creation times recorded.');
// Once the windows have passed, intensive reconciliation stops.
$expired = bmfp_reload($old);
$expired->update_meta_data(OrderMeta::STANDARD_RETURN_WINDOW, gmdate('Y-m-d', time() - 100 * DAY_IN_SECONDS));
$expired->update_meta_data(OrderMeta::UNAUTHORIZED_RETURN_WINDOW, gmdate('Y-m-d', time() - 10 * DAY_IN_SECONDS));
$expired->save();
bmfp_lc()->monitor()->refresh(bmfp_reload($old));
$row = bmfp_index_row($old);
bmfp_assert(null === $row['reconcile_after'] && null === $row['monitor_until'], 'Closed windows end per-order polling (event sync still catches any late event).');

// ---------------------------------------------------------------------------------
WP_CLI::log('Plaid account and environment changes are guarded while payments are monitored');
$https = static fn ($home) => str_replace('http://', 'https://', (string) $home);
add_filter('option_home', $https);
bmfp_reset_world();
bmfp_configure(array('environment' => 'production', 'client_id' => 'prodclient1', 'secret' => 'prod-secret-1', 'link_customization_name' => 'one_account'));
$prod = bmfp_order('11.11');
$prod_transfer = bmfp_lc_transfer($prod);
bmfp_assert('production' === bmfp_lc_meta($prod, OrderMeta::ENVIRONMENT) && bmfp_lc_meta($prod, OrderMeta::ACCOUNT_FINGERPRINT) === Settings::load()->account_fingerprint(), 'The attempt records its environment and account.');
bmfp_assert_same('one_account', (string) ($mock::calls('/link/token/create')[0]['body']['link_customization_name'] ?? ''), 'The Link customization is sent to /link/token/create.');
$settings = get_option(BMFP_TEST_SETTINGS_OPTION);
update_option(BMFP_TEST_SETTINGS_OPTION, array('client_id' => 'otherclient9', 'secret' => 'other-secret', 'title' => 'Bank transfer') + $settings);
$after = Settings::load();
bmfp_assert('prodclient1' === $after->client_id() && 'prod-secret-1' === $after->secret(), 'Switching the Plaid account is refused while a Production payment is in flight.');
bmfp_assert_same('Bank transfer', $after->title(), 'Unrelated settings are still saved.');
update_option(BMFP_TEST_SETTINGS_OPTION, array('environment' => 'sandbox') + get_option(BMFP_TEST_SETTINGS_OPTION));
bmfp_assert_same('production', Settings::load()->environment_name(), 'Switching the environment is refused.');
update_option(BMFP_TEST_SETTINGS_OPTION, array('secret' => '') + get_option(BMFP_TEST_SETTINGS_OPTION));
bmfp_assert_same('prod-secret-1', Settings::load()->secret(), 'Removing the secret is refused.');
update_option(BMFP_TEST_SETTINGS_OPTION, array('secret' => 'prod-secret-rotated') + get_option(BMFP_TEST_SETTINGS_OPTION));
bmfp_assert_same('prod-secret-rotated', Settings::load()->secret(), 'Rotating the secret of the same Client ID is allowed.');
bmfp_lc_events($prod_transfer, array('posted', 'settled', 'funds_available'));
bmfp_assert(bmfp_reload($prod)->is_paid(), 'The payment continues with the rotated secret.');
$prod_settings = get_option(BMFP_TEST_SETTINGS_OPTION);
update_option(BMFP_TEST_SETTINGS_OPTION, array('client_id' => 'otherclient9') + $prod_settings);
bmfp_assert_same('prodclient1', Settings::load()->client_id(), 'Still refused while the ACH return window is open.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_payment_locks SET reconcile_after = NULL, monitor_until = NULL WHERE order_id = %d", $prod->get_id()));
update_option(BMFP_TEST_SETTINGS_OPTION, array('client_id' => 'otherclient9', 'secret' => 'other-secret') + $prod_settings);
bmfp_assert_same('otherclient9', Settings::load()->client_id(), 'Allowed once nothing is monitored anymore.');
remove_filter('option_home', $https);
bmfp_reset_world();
bmfp_configure(array('client_id' => 'sandboxclient1'));
$sandbox_open = bmfp_order('11.11');
bmfp_lc_transfer($sandbox_open);
update_option(BMFP_TEST_SETTINGS_OPTION, array('client_id' => 'sandboxclient2') + get_option(BMFP_TEST_SETTINGS_OPTION));
bmfp_assert_same('sandboxclient2', Settings::load()->client_id(), 'Sandbox moves no real money: allowed with a warning.');
bmfp_configure();

// ---------------------------------------------------------------------------------
WP_CLI::log('Transfer UI requires a valid Link customization in Sandbox and Production');
bmfp_configure(array('link_customization_name' => ''));
bmfp_assert(! bmfp_gateway()->is_available(), 'Sandbox without a Link customization is not offered (no unspecified default customization).');
bmfp_assert_same('failure', bmfp_gateway()->process_payment(bmfp_order('12.00')->get_id())['result'], 'Sandbox without a Link customization fails closed.');
bmfp_assert_same(ConfigurationStatus::INCOMPLETE, ConfigurationStatus::evaluate(Settings::load())['level'], 'Sandbox configuration status: Incomplete.');
bmfp_configure();
bmfp_assert(array() !== $mock::calls('/link/token/create') && array() === array_filter($mock::calls('/link/token/create'), static fn (array $call): bool => '' === (string) ($call['body']['link_customization_name'] ?? '')), 'Every Link token request carried the Link customization.');
add_filter('option_home', $https);
bmfp_configure(array('environment' => 'production', 'link_customization_name' => ''));
$uncustomized = bmfp_order('12.00');
bmfp_assert_same('failure', bmfp_gateway()->process_payment($uncustomized->get_id())['result'], 'Production without a Link customization fails closed.');
bmfp_assert_same(ConfigurationStatus::INCOMPLETE, ConfigurationStatus::evaluate(Settings::load())['level'], 'Configuration status: Incomplete.');
bmfp_configure(array('environment' => 'production', 'link_customization_name' => 'invalid_customization'));
$rejected = bmfp_order('12.00');
bmfp_gateway()->process_payment($rejected->get_id());
try {
    bmfp_lc()->attempts()->issue_link_token(bmfp_reload($rejected));
    bmfp_assert(false, 'Plaid rejected the customization.');
} catch (\Buckmerce\Plaid\Exception\PaymentException $exception) {
    bmfp_assert(true, 'ok');
}
$link_error = get_option(PaymentAttemptService::LAST_LINK_ERROR_OPTION);
bmfp_assert(is_array($link_error) && 'INVALID_LINK_CUSTOMIZATION' === $link_error['code'], 'Diagnostics record the Plaid Link error for the merchant.');
$checks = array_column(ConfigurationStatus::evaluate(Settings::load())['checks'], 'result', 'id');
bmfp_assert_same(ConfigurationStatus::FAIL, $checks['link_error'] ?? '', 'Configuration status shows the rejected customization.');
bmfp_configure();
bmfp_assert_same('production', Settings::load()->environment_name(), 'An open Production authorization also blocks switching to Sandbox.');
// Close the Production attempts of this section (as if their authorization windows ended).
$wpdb->query("UPDATE {$wpdb->prefix}buckmerce_plaid_payment_locks SET payment_state = 'intent_failed', reconcile_after = NULL, monitor_until = NULL WHERE environment = 'production'");
remove_filter('option_home', $https);
delete_option(PaymentAttemptService::LAST_LINK_ERROR_OPTION);
bmfp_configure();
bmfp_assert_same('sandbox', Settings::load()->environment_name(), 'Switching is allowed once nothing is monitored.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Returned payment: explicit semantics, history kept, no same-order re-debit');
bmfp_reset_world();
delete_option('bmfp_test_mails');
$returned = bmfp_order('33.33');
$first_transfer = bmfp_lc_transfer($returned);
$mock::advance($first_transfer);
bmfp_lc()->event_sync()->run();
$returned = bmfp_reload($returned);
$first_paid = $returned->get_date_paid()?->getTimestamp();
bmfp_assert(PaymentState::RETURNED === $returned->get_meta(OrderMeta::PAYMENT_STATE, true) && 'failed' === $returned->get_status(), 'Returned → WooCommerce Failed (unpaid, excluded from revenue).');
bmfp_assert(null !== $first_paid && $first_transfer === $returned->get_transaction_id(), 'Paid date and transaction ID are kept.');
bmfp_assert('' !== (string) $returned->get_meta(OrderMeta::RETURNED_AT, true) && '' !== (string) $returned->get_meta(OrderMeta::FUNDS_AVAILABLE_AT, true), 'Return and funds-available timestamps recorded.');
bmfp_assert(str_contains((string) $returned->get_meta(OrderMeta::FAILURE_DESCRIPTION, true), 'Insufficient'), 'Return reason recorded.');
bmfp_assert(1 === count(array_filter((array) get_option('bmfp_test_mails'), static fn ($mail): bool => str_contains((string) $mail['subject'], 'ACH return'))), 'Merchant emailed.');
$badge = Buckmerce\Plaid\Admin\OrderListColumn::badge(PaymentState::RETURNED, 'R01');
bmfp_assert_same('Bank payment returned (R01)', $badge[0], 'Order list shows the return explicitly.');
$decision = ReturnRetryPolicy::for_order($returned);
bmfp_assert_same(ReturnRetryDecision::BLOCK_UNSUPPORTED_FLOW, $decision->outcome, 'R01 would be retryable only as a marked /transfer/create retry, which Transfer UI cannot send.');
bmfp_assert_same($first_transfer, $decision->original_transfer_id, 'The decision names the original returned transfer.');
bmfp_assert(1 === bmfp_note_count($returned, 'will not be debited again by bank'), 'The merchant is told why in a private note.');
// The WooCommerce pay link must not start another bank debit for this order.
$creates = count($mock::calls('/transfer/intent/create'));
$tokens = count($mock::calls('/link/token/create'));
wc_clear_notices();
bmfp_assert_same('failure', bmfp_gateway()->process_payment($returned->get_id())['result'], 'process_payment refuses a same-order debit after a return.');
bmfp_assert(str_contains(implode(' ', array_column(wc_get_notices('error'), 'notice')), 'cannot be used to pay it again'), 'The customer sees why, without codes.');
wc_clear_notices();
try {
    bmfp_lc()->attempts()->issue_link_token(bmfp_reload($returned));
    bmfp_assert(false, 'No Link session for a returned order.');
} catch (ReturnedPaymentRetryException $exception) {
    bmfp_assert_same(ReturnRetryDecision::BLOCK_UNSUPPORTED_FLOW, $exception->decision->outcome, 'Refused by the return retry policy.');
}
bmfp_assert_same($creates, count($mock::calls('/transfer/intent/create')), 'No Transfer Intent was created.');
bmfp_assert_same($tokens, count($mock::calls('/link/token/create')), 'No Link token was created.');
set_query_var('order-pay', $returned->get_id());
bmfp_assert(! bmfp_gateway()->is_available(), 'Pay by Bank is not offered on the order-pay page of a returned order.');
ob_start();
( new Buckmerce\Plaid\Checkout\PaymentPage() )->returned_payment_notice(bmfp_reload($returned));
bmfp_assert(str_contains((string) ob_get_clean(), 'bmfp-returned-notice'), 'The order-pay form explains why Pay by Bank is missing.');
// The customer tried another payment method in between: the order names that method now, but its
// returned bank payment still rules out Pay by Bank (hidden, explained and refused at the choke point).
$switched = bmfp_reload($returned);
$switched->set_payment_method('cod');
$switched->save();
bmfp_assert(! bmfp_gateway()->is_available(), 'Pay by Bank stays hidden after the order was switched to another payment method.');
ob_start();
( new Buckmerce\Plaid\Checkout\PaymentPage() )->returned_payment_notice(bmfp_reload($returned));
bmfp_assert(str_contains((string) ob_get_clean(), 'bmfp-returned-notice'), 'The explanation is still shown.');
$switched = bmfp_reload($returned);
$switched->set_payment_method(Settings::GATEWAY_ID);
$switched->save();
wc_clear_notices();
bmfp_assert_same('failure', bmfp_gateway()->process_payment($returned->get_id())['result'], 'Selecting Pay by Bank again is still refused.');
wc_clear_notices();
bmfp_assert_same($creates, count($mock::calls('/transfer/intent/create')), 'Still no Transfer Intent.');
set_query_var('order-pay', 0);
bmfp_assert(bmfp_gateway()->is_available(), 'Pay by Bank stays available for other checkouts.');
$history = AttemptHistory::all(bmfp_reload($returned));
bmfp_assert(array() === $history, 'Nothing was archived or replaced: the returned attempt stays the current, auditable attempt.');
bmfp_assert_same($first_transfer, bmfp_lc_meta($returned, OrderMeta::TRANSFER_ID), 'The original transfer is never lost.');
// Late or replayed events of the returned transfer stay harmless.
$mock::add_event($first_transfer, 'returned');
bmfp_lc()->event_sync()->run();
bmfp_assert_same(PaymentState::RETURNED, bmfp_lc_meta($returned, OrderMeta::PAYMENT_STATE), 'A replayed return is a no-op.');
bmfp_assert(1 === bmfp_note_count(bmfp_reload($returned), 'will not be debited again by bank'), 'No duplicate note.');
// Unauthorized returns are blocked by their code (R10: never resubmit).
$unauthorized = ReturnRetryPolicy::for_order(bmfp_reload($kept));
bmfp_assert_same(ReturnRetryDecision::BLOCK_RETURN_CODE, $unauthorized->outcome, 'R10 may never be debited again.');
bmfp_assert_same('R10', $unauthorized->return_code, 'The blocking code is reported.');
// A failure before money moved is not a return: the customer may pay again.
$declined = bmfp_order('22.22');
$declined_transfer = bmfp_lc_transfer($declined);
$mock::advance($declined_transfer);
bmfp_lc()->event_sync()->run();
bmfp_assert_same(PaymentState::FAILED, bmfp_lc_meta($declined, OrderMeta::PAYMENT_STATE), 'Precondition: failed ($22.22).');
bmfp_assert(ReturnRetryPolicy::for_order(bmfp_reload($declined))->allows_new_debit(), 'A failed transfer is not a returned transfer.');
$retry_transfer = bmfp_lc_transfer(bmfp_reload($declined));
bmfp_assert($retry_transfer !== $declined_transfer, 'A failed payment can be paid again with a new attempt.');
$failed_attempt = array_values(array_filter(AttemptHistory::all(bmfp_reload($declined)), static fn (array $entry): bool => $declined_transfer === ($entry['transfer_id'] ?? '')));
bmfp_assert(1 === count($failed_attempt) && PaymentState::FAILED === $failed_attempt[0]['payment_state'], 'The failed attempt stays in the history.');
$mock::add_event($declined_transfer, 'failed');
bmfp_lc()->event_sync()->run();
bmfp_assert_same($retry_transfer, bmfp_lc_meta($declined, OrderMeta::TRANSFER_ID), 'Late events of a retired attempt never touch the new attempt.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Merchant cancellation of a still-cancellable transfer');
$cancel = bmfp_order('26.00');
$cancel_transfer = bmfp_lc_transfer($cancel);
bmfp_assert_same('yes', bmfp_lc_meta($cancel, OrderMeta::TRANSFER_CANCELLABLE), 'Plaid reports the pending transfer as cancellable.');
bmfp_assert_same('cancelled', bmfp_lc()->manual_actions()->cancel_transfer(bmfp_reload($cancel), 1), 'Cancelled at Plaid.');
bmfp_assert(PaymentState::CANCELLED === bmfp_lc_meta($cancel, OrderMeta::PAYMENT_STATE) && 'cancelled' === bmfp_reload($cancel)->get_status(), 'State from Plaid: cancelled; order cancelled.');
$cancel_calls = count($mock::calls('/transfer/cancel'));
bmfp_assert_same('cancelled', bmfp_lc()->manual_actions()->cancel_transfer(bmfp_reload($cancel), 1), 'A repeated cancel reports the existing outcome.');
bmfp_assert_same($cancel_calls, count($mock::calls('/transfer/cancel')), 'A repeated cancel makes no Plaid call.');
$posted = bmfp_order('27.00');
$posted_transfer = bmfp_lc_transfer($posted);
bmfp_lc_events($posted_transfer, array('posted'));
bmfp_assert_same('not_cancellable', bmfp_lc()->manual_actions()->cancel_transfer(bmfp_reload($posted), 1), 'A posted transfer cannot be cancelled.');
bmfp_assert_same(PaymentState::POSTED, bmfp_lc_meta($posted, OrderMeta::PAYMENT_STATE), 'Nothing changed.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Manual review is actionable and never fulfils automatically');
$mismatch = bmfp_order('28.00');
bmfp_lc_transfer($mismatch, '0.28');
bmfp_assert_same(PaymentState::MANUAL_REVIEW, bmfp_lc_meta($mismatch, OrderMeta::PAYMENT_STATE), 'Wrong amount → manual review.');
bmfp_assert(1 === count((new Buckmerce\Plaid\Payment\PaymentAlerts())->for_order($mismatch->get_id())), 'Alert raised.');
bmfp_assert_same('refused', bmfp_lc()->manual_actions()->resolve_review(bmfp_reload($mismatch), 'other', true, 1), 'An attempt with a transfer cannot be released for a new payment.');
bmfp_assert_same('recorded', bmfp_lc()->manual_actions()->resolve_review(bmfp_reload($mismatch), 'contacted_customer', false, 1), 'Decision recorded.');
bmfp_assert(PaymentState::MANUAL_REVIEW === bmfp_lc_meta($mismatch, OrderMeta::PAYMENT_STATE) && ! bmfp_reload($mismatch)->is_paid(), 'Still quarantined, never fulfilled.');
bmfp_assert(0 === count((new Buckmerce\Plaid\Payment\PaymentAlerts())->for_order($mismatch->get_id())) && '' !== bmfp_lc_meta($mismatch, OrderMeta::MANUAL_REVIEW_RESOLUTION), 'Alert cleared, resolution audited.');
$no_transfer = bmfp_order('29.00');
$mock::fail_next('/transfer/intent/create', 'amount:1.00');
bmfp_assert_same('failure', bmfp_gateway()->process_payment($no_transfer->get_id())['result'], 'A mismatching intent is quarantined.');
bmfp_assert_same(PaymentState::MANUAL_REVIEW, bmfp_lc_meta($no_transfer, OrderMeta::PAYMENT_STATE), 'Manual review without a transfer.');
bmfp_assert_same('failure', bmfp_gateway()->process_payment($no_transfer->get_id())['result'], 'No new attempt while in review.');
$index = bmfp_index_row($no_transfer);
bmfp_assert(null !== $index, 'Index row exists.');
bmfp_assert_same('released', bmfp_lc()->manual_actions()->resolve_review(bmfp_reload($no_transfer), 'other', true, 1), 'Released after Plaid confirmed no transfer exists.');
bmfp_assert_same('success', bmfp_gateway()->process_payment($no_transfer->get_id())['result'], 'The customer can pay again.');
bmfp_assert(1 <= count(AttemptHistory::all(bmfp_reload($no_transfer))), 'The released attempt is kept in the history.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Diagnostics are operational and never expose secrets');
as_schedule_single_action(time() - 3 * HOUR_IN_SECONDS, Scheduler::RECONCILE_CONTINUE_HOOK, array(), Scheduler::GROUP);
bmfp_assert(ConfigurationStatus::stalled_actions() >= 1, 'Overdue Buckmerce jobs are detected.');
$status = ConfigurationStatus::evaluate(Settings::load());
bmfp_assert_same(ConfigurationStatus::ATTENTION, $status['level'], 'Stalled background processing requires attention.');
$report = DiagnosticsPage::report();
foreach (array('Monitored bank payments', 'Payments in manual review', 'Refunds pending (in flight/unconfirmed)', 'Event backlog (waiting to be processed)', 'Link customization configured', 'Overdue Buckmerce jobs (>30 min)', 'WP-Cron disabled (DISABLE_WP_CRON)', 'Last verified webhook') as $label) {
    bmfp_assert(array_key_exists($label, $report), 'Diagnostics row: ' . $label);
}
bmfp_assert((int) $report['Monitored bank payments'] >= 1, 'Monitored payments counted.');
bmfp_assert_same('Yes', $report['WP-Cron disabled (DISABLE_WP_CRON)'], 'WP-Cron disabled is reported.');
bmfp_assert(! str_contains((string) wp_json_encode($report), 'test-sandbox-secret'), 'No secret in diagnostics.');
as_unschedule_all_actions(Scheduler::RECONCILE_CONTINUE_HOOK, array(), Scheduler::GROUP);
bmfp_assert(null !== PaymentEpoch::get(bmfp_scope()), 'Epoch recorded.');

WP_CLI::success('Buckmerce lifecycle suite passed (HPOS=' . (getenv('BUCKMERCE_PLAID_EXPECT_HPOS') ?: '?') . ').');
