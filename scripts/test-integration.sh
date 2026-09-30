#!/usr/bin/env bash
# WordPress + WooCommerce integration suite on a disposable site and database.
# Installs ONLY the release ZIP. All writes are confined to the new database,
# whose name must match ^paybridge_test_[a-z0-9]+$.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
pbfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/paybridge-for-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${PAYBRIDGE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/paybridge-for-plaid-$plugin_version.zip"}
if [[ ! -f "$plugin_zip" ]]; then
    printf 'Build the release ZIP (npm run plugin-zip) before running integration tests.\n' >&2
    exit 1
fi
site_dir=$(mktemp -d /tmp/paybridge-integration.XXXXXXXX)
task_id=${site_dir##*.}
database="paybridge_test_${task_id,,}"
database_created=false
wp_cli=(wp --path="$site_dir" --no-color)

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$database_created" == true && "$database" =~ ^paybridge_test_[a-z0-9]+$ ]]; then
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

"${wp_cli[@]}" core download --version="${PAYBRIDGE_PLAID_TEST_WP_VERSION:-7.1}" --locale=en_US --quiet
printf '%s\n' "${PAYBRIDGE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
    --dbname="$database" \
    --dbuser="${PAYBRIDGE_PLAID_TEST_DB_USER:-root}" \
    --dbhost="${PAYBRIDGE_PLAID_TEST_DB_HOST:-localhost}" \
    --dbprefix=pbfp_test_ --skip-check --prompt=dbpass >/dev/null
"${wp_cli[@]}" db create
database_created=true
"${wp_cli[@]}" config set PAYBRIDGE_PLAID_TEST_DATABASE true --raw
"${wp_cli[@]}" config set DISABLE_WP_CRON true --raw
"${wp_cli[@]}" config set WP_DEBUG true --raw
"${wp_cli[@]}" config set WP_DEBUG_LOG true --raw
"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw
"${wp_cli[@]}" core install --url=http://paybridge.test --title='PayBridge integration' \
    --admin_user=pbfp_admin --admin_password=local-test-password \
    --admin_email=admin@example.invalid --skip-email
"${wp_cli[@]}" plugin install woocommerce --version="${PAYBRIDGE_PLAID_TEST_WC_VERSION:-11.1.0}" --quiet
if ! "${wp_cli[@]}" plugin activate woocommerce; then
    # Some local PHP builds abort the first activation while probing image support; activation is idempotent.
    "${wp_cli[@]}" plugin activate woocommerce
fi
"${wp_cli[@]}" option delete wc_installing >/dev/null 2>&1 || true
mkdir -p "$site_dir/wp-content/mu-plugins"
cp "$base_dir/tests/fixtures/disposable-site.php" "$site_dir/wp-content/mu-plugins/pbfp-disposable-site.php"
cp "$base_dir/tests/fixtures/plaid-mock.php" "$site_dir/wp-content/mu-plugins/pbfp-plaid-mock.php"

# WooCommerce's own activation notices (e.g. its bundled Jetpack packages loading translations
# early under WP-CLI) are not PayBridge's; everything logged from here on is checked.
: > "$site_dir/wp-content/debug.log"
# Only the release ZIP is installed; the source tree is never loaded.
"${wp_cli[@]}" plugin install "$plugin_zip" --activate
# Update checks fail by design where the disposable site blocks external HTTP.
activation_problems=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)|wp_version_check\(\)' || true)
if [[ -n "$activation_problems" ]]; then
    printf 'PHP warnings/notices while installing and activating PayBridge:\n%s\n' "$activation_problems" >&2
    exit 1
fi
"${wp_cli[@]}" eval 'if (! \PayBridge\Plaid\Persistence\Installer::schema_is_valid()) { throw new RuntimeException("Fresh activation did not create the PayBridge schema."); }'
"${wp_cli[@]}" option update woocommerce_custom_orders_table_data_sync_enabled yes >/dev/null
# Only warnings raised while PayBridge suites run are relevant.
: > "$site_dir/wp-content/debug.log"

for hpos in no yes; do
    "${wp_cli[@]}" wc hpos sync >/dev/null 2>&1 || true
    "${wp_cli[@]}" option update woocommerce_custom_orders_table_enabled "$hpos" >/dev/null
    for suite in wp-cli-smoke wp-cli-payment-flow wp-cli-webhook-rest wp-cli-refunds wp-cli-lifecycle; do
        PAYBRIDGE_PLAID_EXPECT_HPOS="$hpos" "${wp_cli[@]}" eval-file "$base_dir/tests/Integration/$suite.php" --use-include
    done
    PAYBRIDGE_PLAID_EXPECT_HPOS="$hpos" PAYBRIDGE_PLAID_SITE="$site_dir" php "$base_dir/tests/Integration/concurrency.php"
done

# Update checks fail by design because the disposable site blocks external HTTP.
php_problems=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)' || true)
if [[ -n "$php_problems" ]]; then
    printf 'PHP warnings/notices were logged during the suites:\n%s\n' "$php_problems" >&2
    exit 1
fi

"${wp_cli[@]}" plugin deactivate paybridge-for-plaid
"${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-uninstall-smoke.php" --use-include
printf 'PayBridge integration suite passed.\n'
