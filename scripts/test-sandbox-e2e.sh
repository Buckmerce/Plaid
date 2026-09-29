#!/usr/bin/env bash
# OPTIONAL real Plaid Sandbox gate (CLAUDE.md Task 65). Uses genuine Plaid APIs and
# Plaid Transfer UI; never runs in Production. Requires:
#   PAYBRIDGE_PLAID_SANDBOX_CLIENT_ID, PAYBRIDGE_PLAID_SANDBOX_SECRET,
#   PAYBRIDGE_PLAID_SANDBOX_USERNAME, PAYBRIDGE_PLAID_SANDBOX_PASSWORD (Plaid Sandbox test user)
# plus PAYBRIDGE_PLAID_TEST_DB_* for the disposable database. Secrets are never printed.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
for required in PAYBRIDGE_PLAID_SANDBOX_CLIENT_ID PAYBRIDGE_PLAID_SANDBOX_SECRET PAYBRIDGE_PLAID_SANDBOX_USERNAME PAYBRIDGE_PLAID_SANDBOX_PASSWORD; do
    if [[ -z "${!required:-}" ]]; then
        printf 'Real Plaid Sandbox E2E blocked only by missing credentials (%s).\n' "$required" >&2
        exit 78
    fi
done
plugin_version=$(grep -m1 '^ \* Version:' "$base_dir/paybridge-for-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
plugin_zip=${PAYBRIDGE_PLAID_TEST_PLUGIN_ZIP:-"$base_dir/dist/paybridge-for-plaid-$plugin_version.zip"}
port=${PAYBRIDGE_PLAID_SANDBOX_PORT:-8895}
base_url="http://127.0.0.1:${port}"
site_dir=$(mktemp -d /tmp/paybridge-sandbox.XXXXXXXX)
task_id=${site_dir##*.}
database="paybridge_sandbox_${task_id,,}"
database_created=false
server_pid=''
session="pbfp-sandbox-${task_id,,}"
wp_cli=(wp --path="$site_dir" --no-color)
playwright_cli=(npx --yes --package @playwright/cli playwright-cli --session "$session")
artifacts="$base_dir/output/playwright"

cleanup() {
    local result=$?
    trap - EXIT
    "${playwright_cli[@]}" close >/dev/null 2>&1 || true
    if [[ -n "$server_pid" ]]; then
        pkill -f "S 127.0.0.1:${port} -t ${site_dir}" 2>/dev/null || true
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ "$database_created" == true && "$database" =~ ^paybridge_sandbox_[a-z0-9]+$ ]]; then
        "${wp_cli[@]}" db drop --yes >/dev/null 2>&1 || result=1
    fi
    rm -f "$artifacts/sandbox-start-url.txt"
    if [[ "$result" -eq 0 ]]; then rm -rf "$site_dir"; else printf 'Sandbox site kept for diagnosis: %s\n' "$site_dir" >&2; fi
    exit "$result"
}
trap cleanup EXIT

[[ -f "$plugin_zip" ]] || { printf 'Build the release ZIP first.\n' >&2; exit 1; }
"${wp_cli[@]}" core download --version="${PAYBRIDGE_PLAID_TEST_WP_VERSION:-7.1}" --locale=en_US --quiet
printf '%s\n' "${PAYBRIDGE_PLAID_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create --dbname="$database" \
    --dbuser="${PAYBRIDGE_PLAID_TEST_DB_USER:-root}" --dbhost="${PAYBRIDGE_PLAID_TEST_DB_HOST:-localhost}" \
    --dbprefix=pbfp_sb_ --skip-check --prompt=dbpass >/dev/null
"${wp_cli[@]}" db create
database_created=true
for constant in DISABLE_WP_CRON WP_DEBUG WP_DEBUG_LOG; do "${wp_cli[@]}" config set "$constant" true --raw >/dev/null; done
"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw >/dev/null
"${wp_cli[@]}" core install --url="$base_url" --title='PayBridge Sandbox' --admin_user=pbfp_admin \
    --admin_password=local-test-password --admin_email=admin@example.invalid --skip-email >/dev/null
mkdir -p "$site_dir/wp-content/themes/pbfp-browser-test"
cp -R "$base_dir/tests/fixtures/browser-theme/." "$site_dir/wp-content/themes/pbfp-browser-test/"
"${wp_cli[@]}" theme activate pbfp-browser-test >/dev/null
"${wp_cli[@]}" plugin install woocommerce --version="${PAYBRIDGE_PLAID_TEST_WC_VERSION:-11.1.0}" --quiet
"${wp_cli[@]}" plugin activate woocommerce >/dev/null 2>&1 || "${wp_cli[@]}" plugin activate woocommerce >/dev/null
"${wp_cli[@]}" option delete wc_installing >/dev/null 2>&1 || true
"${wp_cli[@]}" plugin install "$plugin_zip" --activate >/dev/null
checkout_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Classic Checkout' --post_name=classic-checkout --post_content='[woocommerce_checkout]' --post_status=publish --porcelain)
"${wp_cli[@]}" option update woocommerce_checkout_page_id "$checkout_id" >/dev/null
for option in "woocommerce_currency USD" "woocommerce_default_country US:CA" "woocommerce_enable_guest_checkout yes" "woocommerce_coming_soon no"; do
    # shellcheck disable=SC2086
    "${wp_cli[@]}" option update $option >/dev/null
done
"${wp_cli[@]}" rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
"${wp_cli[@]}" eval 'update_option("woocommerce_paybridge_plaid_settings", array("enabled"=>"yes","title"=>"Pay by Bank","description"=>"Securely pay directly from your bank account.","environment"=>"sandbox","client_id"=>getenv("PAYBRIDGE_PLAID_SANDBOX_CLIENT_ID"),"secret"=>getenv("PAYBRIDGE_PLAID_SANDBOX_SECRET"),"funding_account_id"=>"","link_customization_name"=>"","network"=>"same-day-ach","ach_class"=>"web","confirmation_state"=>"funds_available","reconciliation_enabled"=>"yes","debug"=>"yes","delete_data_on_uninstall"=>"no"));'
connection=$("${wp_cli[@]}" eval 'echo (new PayBridge\Plaid\Admin\ConnectionTester())->test(PayBridge\Plaid\Settings\Settings::load())["status"];')
[[ "$connection" == connected ]] || { printf 'Plaid Sandbox connection test failed: %s\n' "$connection" >&2; exit 1; }
products='{'
for amount in 11.11 22.22 33.33; do
    id=$("${wp_cli[@]}" eval '$p = new WC_Product_Simple(); $p->set_name("Sandbox '"$amount"'"); $p->set_regular_price("'"$amount"'"); $p->set_virtual(true); $p->set_status("publish"); echo $p->save();')
    products+="\"$amount\":$id,"
done
products="${products%,}}"
checkout_url=$("${wp_cli[@]}" post url "$checkout_id")

mkdir -p "$artifacts"
(cd "$site_dir" && PHP_CLI_SERVER_WORKERS=4 wp --path="$site_dir" --no-color server --host=127.0.0.1 --port="$port") >"$artifacts/sandbox-server.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 30); do curl -fsS "$base_url" >/dev/null 2>&1 && break; sleep 1; done

config=$(PBFP_PRODUCTS="$products" PBFP_CHECKOUT="$checkout_url" php -r 'echo rawurlencode(json_encode(array("products"=>json_decode(getenv("PBFP_PRODUCTS"),true),"checkout"=>getenv("PBFP_CHECKOUT"),"username"=>getenv("PAYBRIDGE_PLAID_SANDBOX_USERNAME"),"password"=>getenv("PAYBRIDGE_PLAID_SANDBOX_PASSWORD"))));')
pushd "$artifacts" >/dev/null
"${playwright_cli[@]}" open "$base_url/?pbfp_sandbox=$config" --config "$base_dir/tests/E2E/playwright-cli.json" >/dev/null
output=$("${playwright_cli[@]}" run-code --filename "$base_dir/tests/E2E/sandbox-transfer-ui.js" 2>&1 | grep -E 'SANDBOX_ORDERS=|ASSERTION|### Error|Error:' || true)
popd >/dev/null
orders=$(sed -nE 's/.*SANDBOX_ORDERS=(\{[^}]*\}).*/\1/p' <<<"$output" | tr -d '\\')
[[ -n "$orders" ]] || { printf 'Transfer UI automation failed:\n%s\n' "$output" >&2; exit 1; }

# Pull the real Plaid transfer events (no public webhook URL is required for this gate).
"${wp_cli[@]}" eval '$r = (new PayBridge\Plaid\Container())->event_sync()->run(); echo "event sync: ", json_encode($r), "\n";'
PBFP_ORDERS="$orders" "${wp_cli[@]}" eval '
$orders = json_decode(getenv("PBFP_ORDERS"), true);
$expect = array(
    "11.11" => array("state" => "funds_available", "paid" => true),
    "22.22" => array("state" => "failed", "paid" => false),
    "33.33" => array("state" => "returned", "paid" => false, "return" => "R01"),
);
$failed = false;
foreach ($expect as $amount => $want) {
    $order = wc_get_order((int) $orders[$amount]);
    $state = (string) $order->get_meta("_pbfp_payment_state", true);
    $ok = $state === $want["state"] && $order->is_paid() === $want["paid"] && (! isset($want["return"]) || $want["return"] === $order->get_meta("_pbfp_return_code", true)) && "" !== (string) $order->get_meta("_pbfp_transfer_id", true);
    printf("%s  $%s  order #%d  state=%s  wc_status=%s  paid=%s  transfer=%s  return=%s\n", $ok ? "PASS" : "FAIL", $amount, $order->get_id(), $state, $order->get_status(), $order->is_paid() ? "yes" : "no", $order->get_meta("_pbfp_transfer_id", true), $order->get_meta("_pbfp_return_code", true) ?: "-");
    $failed = $failed || ! $ok;
}
if ($failed) { throw new RuntimeException("Real Plaid Sandbox lifecycle assertions failed."); }
'
printf 'Real Plaid Sandbox gate passed: $11.11 success, $22.22 failure, $33.33 return (R01).\n'
