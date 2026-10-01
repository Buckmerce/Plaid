#!/usr/bin/env bash
# WordPress Plugin Check against the release ZIP on a disposable site and database.
# Fails on any ERROR and on any WARNING (there is no allowlist).
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/buckmerce-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${BUCKMERCE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/buckmerce-plaid-$plugin_version.zip"}
if [[ ! -f "$plugin_zip" ]]; then
    printf 'Build the release ZIP (npm run plugin-zip) before running Plugin Check.\n' >&2
    exit 1
fi
site_dir=$(mktemp -d /tmp/buckmerce-plugin-check.XXXXXXXX)
task_id=${site_dir##*.}
database="buckmerce_check_${task_id,,}"
database_created=false
wp_cli=(wp --path="$site_dir" --no-color)

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$database_created" == true && "$database" =~ ^buckmerce_check_[a-z0-9]+$ ]]; then
        "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || result=1
    fi
    rm -rf "$site_dir"
    exit "$result"
}
trap cleanup EXIT

"${wp_cli[@]}" core download --version="${BUCKMERCE_PLAID_TEST_WP_VERSION:-7.1.2}" --locale=en_US --quiet
printf '%s\n' "${BUCKMERCE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
    --dbname="$database" \
    --dbuser="${BUCKMERCE_PLAID_TEST_DB_USER:-root}" \
    --dbhost="${BUCKMERCE_PLAID_TEST_DB_HOST:-localhost}" \
    --dbprefix=bmfp_check_ --skip-check --prompt=dbpass >/dev/null
"${wp_cli[@]}" db create >/dev/null
database_created=true
"${wp_cli[@]}" core install --url=http://buckmerce.test --title='Buckmerce Plugin Check' \
    --admin_user=bmfp_admin --admin_password=local-test-password \
    --admin_email=admin@example.invalid --skip-email >/dev/null
"${wp_cli[@]}" plugin install woocommerce --version="${BUCKMERCE_PLAID_TEST_WC_VERSION:-11.1.2}" --quiet
if ! "${wp_cli[@]}" plugin activate woocommerce >/dev/null; then
    # Some local PHP builds abort the first activation while probing image support; activation is idempotent.
    "${wp_cli[@]}" plugin activate woocommerce >/dev/null
fi
"${wp_cli[@]}" plugin install plugin-check --version="${BUCKMERCE_PLAID_PLUGIN_CHECK_VERSION:-2.1.0}" --activate --quiet
"${wp_cli[@]}" plugin install "$plugin_zip" --activate >/dev/null

report=$("${wp_cli[@]}" plugin check buckmerce-plaid --format=csv --include-experimental 2>&1 || true)
printf '%s\n' "$report"

# No allowlist: the plugin name ("Buckmerce – Bank Payments via Plaid for WooCommerce") follows the
# "for WooCommerce" naming pattern, so Plugin Check must report no ERROR and no WARNING at all.
findings=$(grep -E '^[0-9]+,[0-9]+,(ERROR|WARNING),' <<<"$report" || true)
if [[ -n "$findings" ]]; then
    printf 'Plugin Check reported findings:\n%s\n' "$findings" >&2
    exit 1
fi
if ! grep -qE '^(FILE: |Success: Checks complete)' <<<"$report"; then
    printf 'Plugin Check did not run.\n' >&2
    exit 1
fi
printf 'Plugin Check passed.\n'
