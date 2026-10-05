<?php

/**
 * Bundled translations on a site that runs ONLY the release ZIP.
 * scripts/test-integration.sh exercises bundled PHP/MO catalogues, an external language pack and
 * a string requested before `init`. Proves that WordPress loads the shipped files, that
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

$domain = 'buckmerce-plaid';
$locale = 'ru_RU';
$source = getenv('BMFP_I18N_SOURCE') ?: 'bundled';
$languages = 'external' === $source ? WP_LANG_DIR . '/plugins' : BUCKMERCE_PLAID_DIR . 'languages';

WP_CLI::log('Translation source: ' . $source);
bmfp_assert_same($locale, get_locale(), 'The site locale is ' . $locale . '.');
if ('external' !== $source) {
    bmfp_assert(! is_file(WP_LANG_DIR . "/plugins/$domain-$locale.mo"), 'There is no external MO language pack.');
    bmfp_assert(! is_file(WP_LANG_DIR . "/plugins/$domain-$locale.l10n.php"), 'There is no external PHP language pack.');
}
foreach (array("$domain-$locale.mo", "$domain-$locale.l10n.php") as $file) {
    bmfp_assert(is_file($languages . '/' . $file), 'The installed ZIP contains ' . $file . '.');
}
$compiled = include $languages . "/$domain-$locale.l10n.php";
bmfp_assert(is_array($compiled) && is_array($compiled['messages'] ?? null), 'The compiled PHP translation is readable.');
/** @var array<string, string> $messages */
$messages = $compiled['messages'];
bmfp_assert(count($messages) >= 400, 'The translation covers the whole plugin (' . count($messages) . ' strings).');

if ('1' === getenv('BMFP_I18N_EARLY')) {
    // scripts/test-integration.sh requested this string on after_setup_theme, the way another
    // plugin does when it lists the payment gateways before `init`. WordPress 6.6 and 6.7 do not
    // register a plugin's languages directory themselves, and an unanswered request leaves the
    // text domain untranslated for the rest of the request.
    WP_CLI::log('A string requested before init is translated from the bundled catalogue');
    bmfp_assert_same($messages['Pay by Bank'] ?? null, $GLOBALS['bmfp_early_translation'] ?? null, 'The bundled catalogue is registered while the plugin loads.');
}

// Exercise WordPress's normal lazy loader, including its supported MO-format fallback.
unload_textdomain($domain, true);
if ('mo' === $source) {
    add_filter('translation_file_format', static function (string $format, string $textdomain): string {
        return 'buckmerce-plaid' === $textdomain ? 'mo' : $format;
    }, 10, 2);
}
$loaded_files = array();
add_filter('load_translation_file', static function (string $file, string $textdomain) use (&$loaded_files): string {
    if ('buckmerce-plaid' === $textdomain) {
        $loaded_files[] = wp_normalize_path($file);
    }
    return $file;
}, 10, 2);

WP_CLI::log('WordPress loads every string from the bundled catalogue, and every translation still formats');
$cyrillic = 0;
foreach ($messages as $msgid => $translation) {
    // Not a string literal on purpose: this walks the shipped catalogue, it is not a new string.
    $translated = call_user_func('__', $msgid, $domain);
    bmfp_assert_same($translation, $translated, 'WordPress translates: ' . substr((string) $msgid, 0, 60));
    bmfp_assert('' !== trim($translation), 'Translated: ' . substr((string) $msgid, 0, 60));
    $cyrillic += 1 === preg_match('/\p{Cyrillic}/u', $translation) ? 1 : 0;
    preg_match_all('/%(?:(\d+)\$)?[sdf]/', (string) $msgid, $found);
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
    bmfp_assert('' !== $formatted && ! str_contains($formatted, '%s') && ! str_contains($formatted, '$s'), 'The translation formats with the arguments of: ' . substr((string) $msgid, 0, 60));
}
// Brand and protocol names (Buckmerce for Plaid, PHP, HTTPS, Client ID, …) stay as they are.
bmfp_assert($cyrillic >= count($messages) - 20, 'All but a few proper names are Russian text (' . $cyrillic . ' of ' . count($messages) . ').');
bmfp_assert(is_textdomain_loaded($domain), 'WordPress loaded the bundled text domain.');
bmfp_assert(in_array(wp_normalize_path($languages . "/$domain-$locale" . ('mo' === $source ? '.mo' : '.l10n.php')), $loaded_files, true), 'WordPress loaded the expected catalogue file.');

bmfp_assert(switch_to_locale('en_US'), 'The locale can switch to English.');
bmfp_assert_same('Pay by Bank', call_user_func('__', 'Pay by Bank', $domain), 'English falls back to the source string.');
restore_previous_locale();
bmfp_assert_same($locale, get_locale(), 'The Russian locale is restored.');

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
