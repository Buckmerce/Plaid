<?php

/**
 * Bundled translations on a real WordPress + WooCommerce site that runs ONLY the release ZIP.
 * scripts/test-integration.sh sets WPLANG=ru_RU in wp-config.php for this suite (no language
 * pack is needed for a plugin text domain). Proves that WordPress loads the shipped files, that
 * every string is translated and still formats, and that payment logic does not depend on
 * English text. A translation loaded too early would log a PHP notice, which fails the run.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Admin\ConfigurationStatus;
use Buckmerce\Plaid\Admin\DiagnosticsPage;
use Buckmerce\Plaid\Admin\SiteHealth;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Payment\ReturnRetryPolicy;
use Buckmerce\Plaid\Settings\Settings;

$domain = 'buckmerce-for-plaid';
$locale = 'ru_RU';
$languages = WP_PLUGIN_DIR . '/buckmerce-for-plaid/languages';

WP_CLI::log('The release ZIP ships the template and the compiled translation');
bmfp_assert_same($locale, get_locale(), 'The site locale is ' . $locale . '.');
foreach (array($domain . '.pot', "$domain-$locale.po", "$domain-$locale.mo", "$domain-$locale.l10n.php") as $file) {
    bmfp_assert(is_file($languages . '/' . $file), 'The ZIP contains languages/' . $file . '.');
}
$compiled = include $languages . "/$domain-$locale.l10n.php";
bmfp_assert(is_array($compiled) && is_array($compiled['messages'] ?? null), 'The compiled PHP translation is readable.');
/** @var array<string, string> $messages */
$messages = $compiled['messages'];
bmfp_assert(count($messages) >= 400, 'The translation covers the whole plugin (' . count($messages) . ' strings).');

WP_CLI::log('WordPress loads every string from the bundled files, and every translation still formats');
$cyrillic = 0;
foreach ($messages as $source => $translation) {
    // Not a string literal on purpose: this walks the shipped catalogue, it is not a new string.
    $translated = call_user_func('__', $source, $domain);
    bmfp_assert_same($translation, $translated, 'WordPress translates: ' . substr((string) $source, 0, 60));
    bmfp_assert('' !== trim($translation), 'Translated: ' . substr((string) $source, 0, 60));
    $cyrillic += 1 === preg_match('/\p{Cyrillic}/u', $translation) ? 1 : 0;
    preg_match_all('/%(?:(\d+)\$)?[sdf]/', (string) $source, $found);
    if (array() === $found[0]) {
        continue;
    }
    $numbered = array_filter($found[1], static fn (string $index): bool => '' !== $index);
    $arguments = array_fill(0, array() === $numbered ? count($found[0]) : (int) max($numbered), '7');
    try {
        $formatted = vsprintf($translated, $arguments);
    } catch (\Throwable $exception) {
        $formatted = '';
    }
    bmfp_assert('' !== $formatted && ! str_contains($formatted, '%s') && ! str_contains($formatted, '$s'), 'The translation formats with the arguments of: ' . substr((string) $source, 0, 60));
}
// Brand and protocol names (Buckmerce for Plaid, PHP, HTTPS, Client ID, …) stay as they are.
bmfp_assert($cyrillic >= count($messages) - 20, 'All but a few proper names are Russian text (' . $cyrillic . ' of ' . count($messages) . ').');
bmfp_assert(is_textdomain_loaded($domain), 'The text domain is loaded from the plugin.');

WP_CLI::log('Customer and merchant surfaces are Russian');
delete_option(BMFP_TEST_SETTINGS_OPTION);
bmfp_assert_same('Оплата через банк', Settings::load()->title(), 'The default checkout title is translated.');
bmfp_assert_same('Безопасная оплата напрямую с вашего банковского счёта.', Settings::load()->description(), 'The default checkout description is translated.');
bmfp_configure(array('title' => '', 'description' => ''));
bmfp_reset_world();
$fields = bmfp_gateway()->get_form_fields();
bmfp_assert_same('Название кастомизации Link', $fields['link_customization_name']['title'], 'Gateway settings are translated.');
bmfp_assert_same('Описание в банковской выписке', $fields['statement_descriptor']['title'], 'The statement description setting is translated.');
$report = DiagnosticsPage::report();
bmfp_assert_same(BUCKMERCE_PLAID_VERSION, $report['Версия плагина'] ?? null, 'The diagnostics report is translated and complete.');
bmfp_assert(isset($report['Поток событий (среда/аккаунт)'], $report['Отслеживаемые банковские платежи']), 'Operational diagnostics rows are translated.');
$status = ConfigurationStatus::evaluate(Settings::load());
bmfp_assert(in_array($status['level'], array(ConfigurationStatus::READY, ConfigurationStatus::ATTENTION), true), 'The configuration status is evaluated independently of the language (' . $status['level'] . ').');
$health = ( new SiteHealth() )->test_configuration();
bmfp_assert(1 === preg_match('/\p{Cyrillic}/u', (string) $health['label']), 'Site Health is translated.');
bmfp_assert(str_contains(ReturnRetryPolicy::customer_message(), 'Ваш банк вернул'), 'The returned-payment message for customers is translated.');

WP_CLI::log('A payment runs through its whole lifecycle on a Russian site');
$order = bmfp_order('11.11');
$result = bmfp_gateway()->process_payment($order->get_id());
bmfp_assert_same('success', $result['result'], 'Checkout starts the bank payment.');
$container = new Buckmerce\Plaid\Container();
$token = $container->attempts()->issue_link_token(bmfp_reload($order));
bmfp_assert(null !== $token, 'A Link token is issued.');
$transfer = Buckmerce_Test_Plaid_Mock::authorize($token->token);
$container->completion()->complete(bmfp_reload($order));
Buckmerce_Test_Plaid_Mock::advance($transfer);
$sync = ( new Buckmerce\Plaid\Container() )->event_sync()->run();
bmfp_assert_same('ok', $sync['status'], 'Transfer events are synchronised.');
$paid = bmfp_reload($order);
bmfp_assert(PaymentState::FUNDS_AVAILABLE === $paid->get_meta(OrderMeta::PAYMENT_STATE, true) && $paid->is_paid(), 'The order is paid from verified Plaid events.');
bmfp_assert(bmfp_note_count($paid, 'Buckmerce: средства доступны') >= 1, 'Order notes are written in Russian.');
bmfp_assert(0 === bmfp_note_count($paid, 'Buckmerce: funds available'), 'No English note is written when a translation exists.');

bmfp_configure();
WP_CLI::success('Buckmerce translation suite passed (' . $locale . ', ' . count($messages) . ' strings).');
