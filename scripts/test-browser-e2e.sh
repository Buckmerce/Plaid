#!/usr/bin/env bash
# Deterministic browser E2E suite. Installs ONLY the release ZIP on a disposable
# site; Plaid's API and Link script are replaced by local test doubles.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/buckmerce-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${BUCKMERCE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/buckmerce-plaid-$plugin_version.zip"}
port=${BUCKMERCE_PLAID_E2E_PORT:-8893}
base_url="http://127.0.0.1:${port}"
site_dir=$(mktemp -d /tmp/buckmerce-browser.XXXXXXXX)
task_id=${site_dir##*.}
database="buckmerce_browser_${task_id,,}"
database_created=false
server_pid=''
session="bmfp-e2e-${task_id,,}"
wp_cli=(wp --path="$site_dir" --no-color)
playwright_cli=(npx --yes --package @playwright/cli playwright-cli --session "$session")
artifacts="$base_dir/output/playwright"

# Everything a CI log needs to explain a failed run without the runner at hand.
report_failure() {
    printf '\n== Browser E2E failure diagnostics\n' >&2
    if [[ -n "$server_pid" ]]; then
        if kill -0 "$server_pid" 2>/dev/null; then
            printf 'PHP built-in server (PID %s): running\n' "$server_pid" >&2
        else
            wait "$server_pid" 2>/dev/null
            printf 'PHP built-in server (PID %s): EXITED with status %s\n' "$server_pid" "$?" >&2
        fi
    fi
    printf -- '-- server PHP: %s\n' "$(PHP_INI_SCAN_DIR="${server_ini_scan:-}" php -r 'echo PHP_VERSION, " opcache=", (int) ini_get("opcache.enable"), " jit=", ini_get("opcache.jit") ?: "-", " jit_buffer=", ini_get("opcache.jit_buffer_size") ?: "-";' 2>/dev/null)" >&2
    printf -- '-- wp-server.log (last 60 lines)\n' >&2
    tail -n 60 "$artifacts/wp-server.log" 2>/dev/null >&2 || true
    printf -- '-- wp-content/debug.log (last 60 lines)\n' >&2
    tail -n 60 "$site_dir/wp-content/debug.log" 2>/dev/null >&2 || true
    cp "$site_dir/wp-content/debug.log" "$artifacts/debug.log" 2>/dev/null || true
    local core
    for core in "$site_dir"/core* /tmp/core.php* /tmp/core.*php*; do
        [[ -f "$core" ]] || continue
        printf -- '-- core dump %s\n' "$core" >&2
        if command -v gdb >/dev/null 2>&1; then
            gdb -batch -ex 'bt 40' "$(command -v php)" "$core" 2>/dev/null | tail -n 60 >&2 || true
        fi
    done
}

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$result" -ne 0 ]]; then
        report_failure || true
    fi
    "${playwright_cli[@]}" close >/dev/null 2>&1 || true
    if [[ -n "$server_pid" ]]; then
        # The PHP built-in server forks worker processes; stop all of them.
        pkill -f "S 127.0.0.1:${port} -t ${site_dir}" 2>/dev/null || true
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ "$database_created" == true && "$database" =~ ^buckmerce_browser_[a-z0-9]+$ ]]; then
        "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || result=1
    fi
    if [[ "$result" -eq 0 ]]; then
        rm -rf "$site_dir"
    else
        printf 'Temporary browser site kept for diagnosis: %s\n' "$site_dir" >&2
    fi
    exit "$result"
}
trap cleanup EXIT

if [[ ! -f "$plugin_zip" ]]; then
    printf 'Build the release ZIP (npm run plugin-zip) before running browser tests.\n' >&2
    exit 1
fi

"${wp_cli[@]}" core download --version="${BUCKMERCE_PLAID_TEST_WP_VERSION:-7.1.2}" --locale=en_US --quiet
printf '%s\n' "${BUCKMERCE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
    --dbname="$database" \
    --dbuser="${BUCKMERCE_PLAID_TEST_DB_USER:-root}" \
    --dbhost="${BUCKMERCE_PLAID_TEST_DB_HOST:-localhost}" \
    --dbprefix=bmfp_browser_ --skip-check --prompt=dbpass >/dev/null
"${wp_cli[@]}" db create
database_created=true
for constant in BUCKMERCE_PLAID_TEST_DATABASE BUCKMERCE_PLAID_BROWSER_TEST DISABLE_WP_CRON WP_DEBUG WP_DEBUG_LOG; do
    "${wp_cli[@]}" config set "$constant" true --raw >/dev/null
done
"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw >/dev/null
"${wp_cli[@]}" core install --url="$base_url" --title='Buckmerce browser test' \
    --admin_user=bmfp_admin --admin_password=local-test-password \
    --admin_email=admin@example.invalid --skip-email
mkdir -p "$site_dir/wp-content/themes/bmfp-browser-test"
cp -R "$base_dir/tests/fixtures/browser-theme/." "$site_dir/wp-content/themes/bmfp-browser-test/"
"${wp_cli[@]}" theme activate bmfp-browser-test
"${wp_cli[@]}" plugin install woocommerce --version="${BUCKMERCE_PLAID_TEST_WC_VERSION:-11.1.2}" --quiet
if ! "${wp_cli[@]}" plugin activate woocommerce; then
    "${wp_cli[@]}" plugin activate woocommerce
fi
"${wp_cli[@]}" option delete wc_installing >/dev/null 2>&1 || true
mkdir -p "$site_dir/wp-content/mu-plugins"
for fixture in disposable-site plaid-mock browser-helpers; do
    cp "$base_dir/tests/fixtures/$fixture.php" "$site_dir/wp-content/mu-plugins/bmfp-$fixture.php"
done
# axe-core (accessibility engine) is served only by this disposable site, never shipped.
axe_source="$base_dir/node_modules/axe-core/axe.min.js"
[[ -f "$axe_source" ]] || { printf 'Run npm ci first: axe-core is required for the accessibility checks.\n' >&2; exit 1; }
cp "$axe_source" "$site_dir/wp-content/bmfp-axe.min.js"
# WooCommerce's own activation notices (e.g. its bundled Jetpack packages loading translations
# early under WP-CLI) are not Buckmerce's; everything logged from here on is checked.
: > "$site_dir/wp-content/debug.log"
"${wp_cli[@]}" plugin install "$plugin_zip" --activate
# Update checks fail by design where the disposable site blocks external HTTP.
activation_problems=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)|wp_version_check\(\)' || true)
if [[ -n "$activation_problems" ]]; then
    printf 'PHP warnings/notices while installing and activating Buckmerce:\n%s\n' "$activation_problems" >&2
    exit 1
fi

cart_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Cart' --post_name=cart --post_content='[woocommerce_cart]' --post_status=publish --porcelain)
classic_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Classic Checkout' --post_name=classic-checkout --post_content='[woocommerce_checkout]' --post_status=publish --porcelain)
blocks_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Blocks Checkout' --post_name=blocks-checkout --post_content='placeholder' --post_status=publish --porcelain)
account_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Account' --post_name=account --post_content='[woocommerce_my_account]' --post_status=publish --porcelain)
BMFP_BLOCKS_PAGE_ID="$blocks_id" "${wp_cli[@]}" eval '$m = new ReflectionMethod("WC_Install", "get_checkout_block_content"); $m->setAccessible(true); wp_update_post(array("ID" => (int) getenv("BMFP_BLOCKS_PAGE_ID"), "post_content" => $m->invoke(null)));' --skip-themes
"${wp_cli[@]}" option update woocommerce_cart_page_id "$cart_id" >/dev/null
"${wp_cli[@]}" option update woocommerce_checkout_page_id "$classic_id" >/dev/null
"${wp_cli[@]}" option update bmfp_e2e_classic_page "$classic_id" >/dev/null
"${wp_cli[@]}" option update bmfp_e2e_blocks_page "$blocks_id" >/dev/null
"${wp_cli[@]}" option update woocommerce_myaccount_page_id "$account_id" >/dev/null
"${wp_cli[@]}" option update woocommerce_currency USD >/dev/null
"${wp_cli[@]}" option update woocommerce_default_country US:CA >/dev/null
"${wp_cli[@]}" option update woocommerce_enable_guest_checkout yes >/dev/null
"${wp_cli[@]}" option update woocommerce_enable_signup_and_login_from_checkout no >/dev/null
"${wp_cli[@]}" option update woocommerce_coming_soon no >/dev/null
settings='{"enabled":"yes","title":"Pay by Bank","description":"Securely pay directly from your bank account.","environment":"sandbox","client_id":"browserclientid","secret":"browser-sandbox-secret-value","funding_account_id":"","link_customization_name":"browser_one_account","statement_descriptor":"PAYMENT","network":"same-day-ach","confirmation_state":"funds_available","debug":"yes","delete_data_on_uninstall":"no"}'
"${wp_cli[@]}" option update woocommerce_buckmerce_plaid_settings "$settings" --format=json >/dev/null
product_id=$("${wp_cli[@]}" eval '$p = new WC_Product_Simple(); $p->set_name("Buckmerce Test Product"); $p->set_regular_price("11.11"); $p->set_virtual(true); $p->set_status("publish"); echo $p->save();')
"${wp_cli[@]}" rewrite structure '/%postname%/' --hard >/dev/null
: > "$site_dir/wp-content/debug.log"

mkdir -p "$artifacts"
if curl -s -o /dev/null "http://127.0.0.1:${port}/" 2>/dev/null; then
    printf 'Port %s is already in use; set BUCKMERCE_PLAID_E2E_PORT to a free port.\n' "$port" >&2
    exit 1
fi
# Allow core dumps of the server so a crash can be explained (report_failure prints a backtrace).
ulimit -c unlimited 2>/dev/null || true
# The server's PHP settings are part of the test environment (tests/fixtures/php-server: JIT off, see the
# file for the reproduced PHP crash; scripts/lib/test-env.sh builds the scan path).
server_ini_scan=$(bmfp_server_ini_scan)
(cd "$site_dir" && PHP_INI_SCAN_DIR="$server_ini_scan" PHP_CLI_SERVER_WORKERS="${BUCKMERCE_PLAID_E2E_SERVER_WORKERS:-4}" wp --path="$site_dir" --no-color server --host=127.0.0.1 --port="$port") >"$artifacts/wp-server.log" 2>&1 &
server_pid=$!
printf 'Browser suite web server: PHP %s\n' "$(PHP_INI_SCAN_DIR="$server_ini_scan" php -r 'echo PHP_VERSION, ", opcache ", ini_get("opcache.enable") ? "on" : "off", ", JIT ", ini_get("opcache.jit") ?: "-";' 2>/dev/null)"
for _ in $(seq 1 30); do
    curl -fsS "$base_url" >/dev/null 2>&1 && break
    sleep 1
done
curl -fsS "$base_url" >/dev/null

pushd "$artifacts" >/dev/null
"${playwright_cli[@]}" open "$base_url/?bmfp_e2e_product=$product_id" --config "$base_dir/tests/E2E/playwright-cli.json" >/dev/null
run_output=$("${playwright_cli[@]}" run-code --filename "$base_dir/tests/E2E/browser-smoke.js" 2>&1) || true
popd >/dev/null
printf '%s\n' "$run_output" | grep -E 'E2E ASSERTION FAILED|phase:|### Error|Buckmerce browser smoke passed' | grep -v 'throw new Error\|error.message\|return ' | cut -c1-2000 || true
# playwright-cli can end a run without an error (e.g. on an unexpected browser dialog, reported as
# "Modal state"). Only the suite's own final return value proves that every step ran.
if ! grep -q 'Buckmerce browser smoke passed; accessibility scans:' <<<"$run_output"; then
    printf 'The browser suite did not reach its end (no success result from the test script).\n' >&2
    exit 1
fi

php_problems=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$site_dir/wp-content/debug.log" 2>/dev/null | grep -Ev 'wp_update_(plugins|themes)\(\)|wp_version_check\(\)' || true)
if [[ -n "$php_problems" ]]; then
    printf 'PHP warnings/notices during the browser suite:\n%s\n' "$php_problems" >&2
    exit 1
fi
printf 'Buckmerce browser E2E passed: settings, diagnostics, Classic and Blocks checkout, payment page, double-click guard, failure/exit/retry UX, access control, WooCommerce admin refunds, returned-payment indicator, accessibility (axe WCAG 2.2 AA, keyboard, focus).\n'
