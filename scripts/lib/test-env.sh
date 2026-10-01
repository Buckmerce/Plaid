# shellcheck shell=bash
# Shared environment for the PayBridge test scripts (sourced, never executed).
#
# 1. Loads the gitignored project .env when present, so local runs need no exports.
# 2. Falls back to the [client] section of ~/.my.cnf for the disposable test database
#    credentials when PAYBRIDGE_PLAID_TEST_DB_USER is not set.
# Values are never printed.

pbfp_base_dir=${pbfp_base_dir:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}

if [[ -f "$pbfp_base_dir/.env" ]]; then
    set -a
    # shellcheck disable=SC1091
    . "$pbfp_base_dir/.env"
    set +a
fi

if [[ -z "${PAYBRIDGE_PLAID_TEST_DB_USER:-}" && -r "$HOME/.my.cnf" ]]; then
    eval "$(php -r '
        $ini = @parse_ini_file(getenv("HOME") . "/.my.cnf", true, INI_SCANNER_RAW);
        $client = is_array($ini) && isset($ini["client"]) && is_array($ini["client"]) ? $ini["client"] : array();
        foreach (array("user" => "USER", "password" => "PASSWORD", "host" => "HOST") as $key => $name) {
            if (isset($client[$key]) && "" !== trim((string) $client[$key])) {
                echo "export PAYBRIDGE_PLAID_TEST_DB_" . $name . "=" . escapeshellarg(trim((string) $client[$key], " \t\"\x27")) . "\n";
            }
        }
    ')"
fi

# PHP_INI_SCAN_DIR of the disposable sites' built-in web server (browser and Sandbox suites): PHP's
# own scan directory, then tests/fixtures/php-server (OPcache JIT off: setup-php's default tracing JIT
# crashes PHP 8.2's built-in server workers), then PAYBRIDGE_PLAID_E2E_PHP_INI_DIR when set (loaded
# last, so it can override the fixture, e.g. to reproduce an engine configuration on purpose).
pbfp_server_ini_scan() {
    local scan="${PHP_INI_SCAN_DIR:-}:$pbfp_base_dir/tests/fixtures/php-server"
    if [[ -n "${PAYBRIDGE_PLAID_E2E_PHP_INI_DIR:-}" ]]; then
        scan="${scan}:${PAYBRIDGE_PLAID_E2E_PHP_INI_DIR}"
    fi
    printf '%s' "$scan"
}
