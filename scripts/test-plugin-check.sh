#!/usr/bin/env bash
# WordPress Plugin Check against the release ZIP on a disposable site and database.
# Fails on any ERROR and on any WARNING outside the documented allowlist.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
pbfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/paybridge-for-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${PAYBRIDGE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/paybridge-for-plaid-$plugin_version.zip"}
if [[ ! -f "$plugin_zip" ]]; then
    printf 'Build the release ZIP (npm run plugin-zip) before running Plugin Check.\n' >&2
    exit 1
fi
site_dir=$(mktemp -d /tmp/paybridge-plugin-check.XXXXXXXX)
task_id=${site_dir##*.}
database="paybridge_check_${task_id,,}"
database_created=false
wp_cli=(wp --path="$site_dir" --no-color)

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$database_created" == true && "$database" =~ ^paybridge_check_[a-z0-9]+$ ]]; then
        "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || result=1
    fi
    rm -rf "$site_dir"
    exit "$result"
}
trap cleanup EXIT

"${wp_cli[@]}" core download --version="${PAYBRIDGE_PLAID_TEST_WP_VERSION:-7.1.2}" --locale=en_US --quiet
printf '%s\n' "${PAYBRIDGE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
    --dbname="$database" \
    --dbuser="${PAYBRIDGE_PLAID_TEST_DB_USER:-root}" \
    --dbhost="${PAYBRIDGE_PLAID_TEST_DB_HOST:-localhost}" \
    --dbprefix=pbfp_check_ --skip-check --prompt=dbpass >/dev/null
"${wp_cli[@]}" db create >/dev/null
database_created=true
"${wp_cli[@]}" core install --url=http://paybridge.test --title='PayBridge Plugin Check' \
    --admin_user=pbfp_admin --admin_password=local-test-password \
    --admin_email=admin@example.invalid --skip-email >/dev/null
"${wp_cli[@]}" plugin install woocommerce --version="${PAYBRIDGE_PLAID_TEST_WC_VERSION:-11.1.2}" --quiet
if ! "${wp_cli[@]}" plugin activate woocommerce >/dev/null; then
    # Some local PHP builds abort the first activation while probing image support; activation is idempotent.
    "${wp_cli[@]}" plugin activate woocommerce >/dev/null
fi
"${wp_cli[@]}" plugin install plugin-check --version="${PAYBRIDGE_PLAID_PLUGIN_CHECK_VERSION:-2.1.0}" --activate --quiet
"${wp_cli[@]}" plugin install "$plugin_zip" --activate >/dev/null

report=$("${wp_cli[@]}" plugin check paybridge-for-plaid --format=csv --include-experimental 2>&1 || true)
printf '%s\n' "$report"

# The product name is fixed by the specification ("PayBridge for Plaid — WooCommerce Pay by Bank");
# Plugin Check flags the embedded WooCommerce trademark as a WARNING, which is documented in
# docs/CI_CD_RELEASE.md. Everything else must be clean.
findings=$(grep -E '^[0-9]+,[0-9]+,(ERROR|WARNING),' <<<"$report" | grep -Ev ',WARNING,trademarked_term,' || true)
if [[ -n "$findings" ]]; then
    printf 'Plugin Check reported findings:\n%s\n' "$findings" >&2
    exit 1
fi
if ! grep -qE '^(FILE: |Success: Checks complete)' <<<"$report"; then
    printf 'Plugin Check did not run.\n' >&2
    exit 1
fi
printf 'Plugin Check passed.\n'
