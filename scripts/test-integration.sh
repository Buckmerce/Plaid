#!/usr/bin/env bash
# WordPress + WooCommerce integration suite on a disposable site and database.
# Installs ONLY the release ZIP. All writes are confined to the new database,
# whose name must match ^buckmerce_test_[a-z0-9]+$.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/buckmerce-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${BUCKMERCE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/buckmerce-plaid-$plugin_version.zip"}
if [[ ! -f "$plugin_zip" ]]; then
    printf 'Build the release ZIP (npm run plugin-zip) before running integration tests.\n' >&2
    exit 1
fi
site_dir=$(mktemp -d /tmp/buckmerce-integration.XXXXXXXX)
task_id=${site_dir##*.}
database="buckmerce_test_${task_id,,}"
database_created=false
wp_cli=(wp --path="$site_dir" --no-color)

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$database_created" == true && "$database" =~ ^buckmerce_test_[a-z0-9]+$ ]]; then
        "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || result=1
    fi
    if [[ "$result" -eq 0 ]]; then
        rm -rf "$site_dir"
    else
        printf 'Temporary integration site kept for diagnosis: %s\n' "$site_dir" >&2
    fi
    exit "$result"
}
trap cleanup EXIT

"${wp_cli[@]}" core download --version="${BUCKMERCE_PLAID_TEST_WP_VERSION:-7.1.2}" --locale=en_US --quiet
printf '%s\n' "${BUCKMERCE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
    --dbname="$database" \
    --dbuser="${BUCKMERCE_PLAID_TEST_DB_USER:-root}" \
    --dbhost="${BUCKMERCE_PLAID_TEST_DB_HOST:-localhost}" \
    --dbprefix=bmfp_test_ --skip-check --prompt=dbpass >/dev/null
"${wp_cli[@]}" db create
database_created=true
"${wp_cli[@]}" config set BUCKMERCE_PLAID_TEST_DATABASE true --raw
"${wp_cli[@]}" config set DISABLE_WP_CRON true --raw
"${wp_cli[@]}" config set WP_DEBUG true --raw
"${wp_cli[@]}" config set WP_DEBUG_LOG true --raw
"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw
"${wp_cli[@]}" core install --url=http://buckmerce.test --title='Buckmerce integration' \
    --admin_user=bmfp_admin --admin_password=local-test-password \
    --admin_email=admin@example.invalid --skip-email
"${wp_cli[@]}" plugin install woocommerce --version="${BUCKMERCE_PLAID_TEST_WC_VERSION:-11.1.2}" --quiet
if ! "${wp_cli[@]}" plugin activate woocommerce; then
    # Some local PHP builds abort the first activation while probing image support; activation is idempotent.
    "${wp_cli[@]}" plugin activate woocommerce
fi
"${wp_cli[@]}" option delete wc_installing >/dev/null 2>&1 || true
mkdir -p "$site_dir/wp-content/mu-plugins"
cp "$base_dir/tests/fixtures/disposable-site.php" "$site_dir/wp-content/mu-plugins/bmfp-disposable-site.php"
cp "$base_dir/tests/fixtures/plaid-mock.php" "$site_dir/wp-content/mu-plugins/bmfp-plaid-mock.php"

# WooCommerce's own activation notices (e.g. its bundled Jetpack packages loading translations
# early under WP-CLI) are not Buckmerce's; everything logged from here on is checked.
: > "$site_dir/wp-content/debug.log"
# Only the release ZIP is installed; the source tree is never loaded.
"${wp_cli[@]}" plugin install "$plugin_zip" --activate
# Update checks fail by design where the disposable site blocks external HTTP.
activation_problems=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)|wp_version_check\(\)' || true)
if [[ -n "$activation_problems" ]]; then
    printf 'PHP warnings/notices while installing and activating Buckmerce:\n%s\n' "$activation_problems" >&2
    exit 1
fi
"${wp_cli[@]}" eval 'if (! \Buckmerce\Plaid\Persistence\Installer::schema_is_valid()) { throw new RuntimeException("Fresh activation did not create the Buckmerce schema."); }'
"${wp_cli[@]}" option update woocommerce_custom_orders_table_data_sync_enabled yes >/dev/null
# Only warnings raised while Buckmerce suites run are relevant.
: > "$site_dir/wp-content/debug.log"

for hpos in no yes; do
    "${wp_cli[@]}" wc hpos sync >/dev/null 2>&1 || true
    "${wp_cli[@]}" option update woocommerce_custom_orders_table_enabled "$hpos" >/dev/null
    for suite in wp-cli-smoke wp-cli-commands wp-cli-payment-flow wp-cli-webhook-rest wp-cli-refunds wp-cli-lifecycle wp-cli-accounts wp-cli-maintenance wp-cli-migration; do
        BUCKMERCE_PLAID_EXPECT_HPOS="$hpos" "${wp_cli[@]}" eval-file "$base_dir/tests/Integration/$suite.php" --use-include
    done
    BUCKMERCE_PLAID_EXPECT_HPOS="$hpos" BUCKMERCE_PLAID_SITE="$site_dir" php "$base_dir/tests/Integration/concurrency.php"
done

# Translations must work from the installed ZIP, without a separately downloaded language pack.
"${wp_cli[@]}" config set WPLANG ru_RU >/dev/null
"${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-i18n.php" --use-include
BMFP_I18N_SOURCE=mo "${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-i18n.php" --use-include
# Another plugin may ask for a Buckmerce string before `init` (for example by listing the payment gateways).
BMFP_I18N_EARLY=1 "${wp_cli[@]}" \
    --exec='WP_CLI::add_wp_hook("after_setup_theme", static function (): void { $GLOBALS["bmfp_early_translation"] = call_user_func("__", "Pay by Bank", "buckmerce-plaid"); });' \
    eval-file "$base_dir/tests/Integration/wp-cli-i18n.php" --use-include
# WordPress.org language packs keep priority over the bundled catalogue.
mkdir -p "$site_dir/wp-content/languages/plugins"
cp "$site_dir/wp-content/plugins/buckmerce-plaid/languages/buckmerce-plaid-ru_RU.mo" \
    "$site_dir/wp-content/plugins/buckmerce-plaid/languages/buckmerce-plaid-ru_RU.l10n.php" \
    "$site_dir/wp-content/languages/plugins/"
BMFP_I18N_SOURCE=external "${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-i18n.php" --use-include
"${wp_cli[@]}" config delete WPLANG >/dev/null

# Update checks fail by design because the disposable site blocks external HTTP.
php_problems=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)' || true)
if [[ -n "$php_problems" ]]; then
    printf 'PHP warnings/notices were logged during the suites:\n%s\n' "$php_problems" >&2
    exit 1
fi

"${wp_cli[@]}" plugin deactivate buckmerce-plaid
"${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-uninstall-smoke.php" --use-include
printf 'Buckmerce integration suite passed.\n'
