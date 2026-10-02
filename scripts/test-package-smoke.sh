#!/usr/bin/env bash
# Pristine-install smoke test of the release ZIP: WordPress + WooCommerce + the ZIP, nothing else
# (no test doubles, no mu-plugins, no source tree). Each order-storage mode is a separate,
# fresh disposable store whose HPOS setting is chosen before any order exists — the way a
# merchant's store is set up — so WooCommerce is never forced through an unsupported switch of
# its authoritative order storage. Verifies installation, activation, schema, settings,
# Classic/Blocks registration, refunds support, REST routes, Action Scheduler, diagnostics,
# Site Health, the order panel, deactivation and the default (data-preserving) uninstall, with
# zero PHP warnings, notices or deprecations.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/buckmerce-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${BUCKMERCE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/buckmerce-plaid-$plugin_version.zip"}
[[ -f "$plugin_zip" ]] || { printf 'Build the release ZIP first.\n' >&2; exit 1; }
if [[ -f "$plugin_zip.sha256" ]]; then
    (cd "$(dirname "$plugin_zip")" && sha256sum -c "$(basename "$plugin_zip").sha256" >/dev/null) || { printf 'ZIP does not match its recorded SHA-256.\n' >&2; exit 1; }
fi

site_dir=''
database=''
database_created=false
wp_cli=()

destroy_site() {
    if [[ "$database_created" == true && "$database" =~ ^buckmerce_test_[a-z0-9]+$ ]]; then
        "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || return 1
    fi
    database_created=false
    [[ -z "$site_dir" ]] || rm -rf "$site_dir"
    site_dir=''
}

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$result" -eq 0 ]]; then
        destroy_site || result=1
    elif [[ -n "$site_dir" ]]; then
        printf 'Smoke site kept for diagnosis: %s\n' "$site_dir" >&2
        if [[ -f "$site_dir/wp-content/debug.log" ]]; then
            printf '== debug.log (tail)\n' >&2
            tail -n 40 "$site_dir/wp-content/debug.log" >&2 || true
        fi
        if [[ "$database_created" == true && "$database" =~ ^buckmerce_test_[a-z0-9]+$ ]]; then
            "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || true
        fi
    fi
    exit "$result"
}
trap cleanup EXIT

problems() {
    grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)|wp_version_check\(\)' || true
}

smoke_store() {
    local hpos=$1
    site_dir=$(mktemp -d /tmp/buckmerce-smoke.XXXXXXXX)
    local task_id=${site_dir##*.}
    database="buckmerce_test_${task_id,,}"
    wp_cli=(wp --path="$site_dir" --no-color)

    printf '== Fresh store (HPOS=%s): WordPress %s + WooCommerce %s\n' "$hpos" "${BUCKMERCE_PLAID_TEST_WP_VERSION:-7.1.2}" "${BUCKMERCE_PLAID_TEST_WC_VERSION:-11.1.2}"
    "${wp_cli[@]}" core download --version="${BUCKMERCE_PLAID_TEST_WP_VERSION:-7.1.2}" --locale=en_US --quiet
    printf '%s\n' "${BUCKMERCE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create --dbname="$database" \
        --dbuser="${BUCKMERCE_PLAID_TEST_DB_USER:-root}" --dbhost="${BUCKMERCE_PLAID_TEST_DB_HOST:-localhost}" \
        --dbprefix=bmfp_smoke_ --skip-check --prompt=dbpass >/dev/null
    "${wp_cli[@]}" db create >/dev/null
    database_created=true
    for constant in WP_DEBUG WP_DEBUG_LOG; do "${wp_cli[@]}" config set "$constant" true --raw >/dev/null; done
    "${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw >/dev/null
    "${wp_cli[@]}" core install --url=http://buckmerce-smoke.test --title='Buckmerce smoke' --admin_user=bmfp_admin \
        --admin_password=local-test-password --admin_email=admin@example.invalid --skip-email >/dev/null
    "${wp_cli[@]}" plugin install woocommerce --version="${BUCKMERCE_PLAID_TEST_WC_VERSION:-11.1.2}" --quiet
    "${wp_cli[@]}" plugin activate woocommerce >/dev/null 2>&1 || "${wp_cli[@]}" plugin activate woocommerce >/dev/null
    "${wp_cli[@]}" option delete wc_installing >/dev/null 2>&1 || true
    # The store's order storage is chosen while it has no orders (a supported WooCommerce setting change).
    "${wp_cli[@]}" option update woocommerce_custom_orders_table_enabled "$hpos" >/dev/null
    : > "$site_dir/wp-content/debug.log"

    printf '== Install and activate the release ZIP only\n'
    "${wp_cli[@]}" plugin install "$plugin_zip" --activate >/dev/null
    [[ -z "$(problems)" ]] || { printf 'PHP problems on activation:\n%s\n' "$(problems)" >&2; return 1; }
    [[ ! -d "$site_dir/wp-content/mu-plugins" || -z "$(ls -A "$site_dir/wp-content/mu-plugins")" ]] || { printf 'The pristine site must have no mu-plugins.\n' >&2; return 1; }

    printf '== Runtime checks (HPOS=%s)\n' "$hpos"
    BMFP_HPOS="$hpos" "${wp_cli[@]}" eval '
        $fail = static function (string $message): void { WP_CLI::error("SMOKE: " . $message); };
        $version = BUCKMERCE_PLAID_VERSION;
        if (! Buckmerce\Plaid\Persistence\Installer::schema_is_valid() || Buckmerce\Plaid\Persistence\Installer::SCHEMA_VERSION !== get_option("buckmerce_plaid_schema_version")) { $fail("schema"); }
        if ((getenv("BMFP_HPOS") === "yes") !== Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) { $fail("HPOS mode"); }
        foreach (array("custom_order_tables", "cart_checkout_blocks") as $feature) {
            $compatible = Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature($feature);
            if (! in_array("buckmerce-plaid/buckmerce-plaid.php", $compatible["compatible"] ?? array(), true)) { $fail("compatibility " . $feature); }
        }
        WC()->payment_gateways()->init();
        $gateway = WC()->payment_gateways()->payment_gateways()["buckmerce_plaid"] ?? null;
        if (! $gateway instanceof WC_Payment_Gateway || ! $gateway->supports("refunds") || ! $gateway->supports("products")) { $fail("gateway registration and refund capability"); }
        if ($gateway->is_available()) { $fail("an unconfigured gateway must not be offered"); }
        $fields = $gateway->get_form_fields();
        foreach (array("environment", "client_id", "secret", "link_customization_name", "statement_descriptor", "confirmation_state") as $field) { if (! isset($fields[$field])) { $fail("setting " . $field); } }
        $html = $gateway->generate_settings_html($fields, false);
        if (! str_contains($html, "bmfp-status-panel") || ! str_contains($html, "Incomplete")) { $fail("settings screen and status"); }
        $routes = rest_get_server()->get_routes();
        foreach (array("/buckmerce-plaid/v1/webhook", "/buckmerce-plaid/v1/link-token", "/buckmerce-plaid/v1/complete") as $route) { if (! isset($routes[$route])) { $fail("REST route " . $route); } }
        $registry = Automattic\WooCommerce\Blocks\Package::container()->get(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class);
        if (! $registry->is_registered("buckmerce_plaid")) { do_action("woocommerce_blocks_payment_method_type_registration", $registry); }
        if (! $registry->is_registered("buckmerce_plaid")) { $fail("Checkout Blocks registration"); }
        if (! function_exists("as_schedule_single_action")) { $fail("Action Scheduler"); }
        $report = Buckmerce\Plaid\Admin\DiagnosticsPage::report();
        if ($version !== $report["Plugin version"] || "0" !== $report["Monitored bank payments"]) { $fail("diagnostics"); }
        $health = new Buckmerce\Plaid\Admin\SiteHealth();
        foreach (array($health->test_configuration(), $health->test_background()) as $test) { if (! isset($test["status"], $test["label"])) { $fail("Site Health"); } }
        $order = wc_create_order();
        $order->set_payment_method("buckmerce_plaid");
        $order->save();
        wp_set_current_user((int) get_user_by("login", "bmfp_admin")->ID);
        ob_start();
        (new Buckmerce\Plaid\Admin\OrderMetaBox())->render(wc_get_order($order->get_id()));
        if (! str_contains((string) ob_get_clean(), "bmfp-order-panel")) { $fail("order panel"); }
        // Everything the plugin runs is inside the installed ZIP: its main file, every loaded class
        // (the prefixed library included) and its assets.
        $root = wp_normalize_path(WP_PLUGIN_DIR . "/buckmerce-plaid/");
        if ($root . "buckmerce-plaid.php" !== wp_normalize_path(BUCKMERCE_PLAID_FILE) || $root !== wp_normalize_path(BUCKMERCE_PLAID_DIR)) { $fail("the plugin does not run from " . $root); }
        if (! str_ends_with(BUCKMERCE_PLAID_URL, "/wp-content/plugins/buckmerce-plaid/")) { $fail("asset URL base " . BUCKMERCE_PLAID_URL); }
        $loaded = 0;
        foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
            if (! str_starts_with($class, "Buckmerce\\Plaid\\")) { continue; }
            ++$loaded;
            if (! str_starts_with(wp_normalize_path((string) (new ReflectionClass($class))->getFileName()), $root)) { $fail("loaded from outside the installed ZIP: " . $class); }
        }
        if ($loaded < 20) { $fail("the plugin classes were not loaded"); }
        if (! class_exists("Buckmerce\\Plaid\\Vendor\\Firebase\\JWT\\JWT") || class_exists("Firebase\\JWT\\JWT", false)) { $fail("the bundled JWT library must be the prefixed copy of the ZIP"); }
        foreach (array("assets/admin-settings.css", "assets/payment-page.css", "assets/build/admin-settings.js", "assets/build/blocks.js", "assets/build/payment-page.js", "assets/images/buckmerce-mark.svg", "composer.json") as $shipped) {
            if (! is_readable(BUCKMERCE_PLAID_DIR . $shipped)) { $fail("missing shipped file " . $shipped); }
        }
        foreach (array("tests", "docs", "scripts", "resources", "languages", "vendor", "node_modules", ".github", ".env", "package.json", "README.md") as $development) {
            if (file_exists(BUCKMERCE_PLAID_DIR . $development)) { $fail("development file installed: " . $development); }
        }
        WP_CLI::success("Runtime checks passed for " . $version);
    '
    [[ -z "$(problems)" ]] || { printf 'PHP problems during runtime checks:\n%s\n' "$(problems)" >&2; return 1; }

    printf '== Deactivate and uninstall with default settings (financial data retained)\n'
    "${wp_cli[@]}" eval 'update_option("woocommerce_buckmerce_plaid_settings", array("enabled" => "no", "delete_data_on_uninstall" => "no"));'
    "${wp_cli[@]}" plugin deactivate buckmerce-plaid >/dev/null
    "${wp_cli[@]}" eval 'foreach (array("buckmerce_plaid_transfer_event_sync", "buckmerce_plaid_reconcile", "buckmerce_plaid_reconcile_continue") as $hook) { if (as_has_scheduled_action($hook, array(), "buckmerce-plaid")) { WP_CLI::error("Deactivation must unschedule " . $hook); } }'
    "${wp_cli[@]}" plugin uninstall buckmerce-plaid >/dev/null
    [[ ! -d "$site_dir/wp-content/plugins/buckmerce-plaid" ]] || { printf 'Plugin files were not removed.\n' >&2; return 1; }
    "${wp_cli[@]}" eval 'global $wpdb; if ($wpdb->prefix . "buckmerce_plaid_refunds" !== $wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}buckmerce_plaid_refunds\"") || false === get_option("woocommerce_buckmerce_plaid_settings")) { WP_CLI::error("Default uninstall must keep Buckmerce data."); } WP_CLI::success("Default uninstall kept settings and payment history.");'
    [[ -z "$(problems)" ]] || { printf 'PHP problems during uninstall:\n%s\n' "$(problems)" >&2; return 1; }
    printf 'Pristine store passed (HPOS=%s): %s on WordPress %s + WooCommerce %s.\n' "$hpos" "$(basename "$plugin_zip")" "$("${wp_cli[@]}" core version)" "$("${wp_cli[@]}" eval 'echo WC_VERSION;' 2>/dev/null || echo 'n/a')"
    destroy_site
}

for hpos in yes no; do
    smoke_store "$hpos"
done
printf 'Package smoke test passed: %s on fresh HPOS and legacy-storage stores.\n' "$(basename "$plugin_zip")"
