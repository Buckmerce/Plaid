# shellcheck shell=bash
# Shared environment for the Buckmerce test scripts (sourced, never executed).
#
# 1. Loads the gitignored project .env when present, so local runs need no exports.
# 2. Falls back to the [client] section of ~/.my.cnf for the disposable test database
#    credentials when BUCKMERCE_PLAID_TEST_DB_USER is not set.
# Values are never printed.

bmfp_base_dir=${bmfp_base_dir:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}

if [[ -f "$bmfp_base_dir/.env" ]]; then
    set -a
    # shellcheck disable=SC1091
    . "$bmfp_base_dir/.env"
    set +a
fi

if [[ -z "${BUCKMERCE_PLAID_TEST_DB_USER:-}" && -r "$HOME/.my.cnf" ]]; then
    eval "$(php -r '
        $ini = @parse_ini_file(getenv("HOME") . "/.my.cnf", true, INI_SCANNER_RAW);
        $client = is_array($ini) && isset($ini["client"]) && is_array($ini["client"]) ? $ini["client"] : array();
        foreach (array("user" => "USER", "password" => "PASSWORD", "host" => "HOST") as $key => $name) {
            if (isset($client[$key]) && "" !== trim((string) $client[$key])) {
                echo "export BUCKMERCE_PLAID_TEST_DB_" . $name . "=" . escapeshellarg(trim((string) $client[$key], " \t\"\x27")) . "\n";
            }
        }
    ')"
fi

# The Playwright CLI release that drives the browser and Sandbox suites. Pinned: `npx --yes` without
# a version runs whatever was published last, so a new CLI release could fail an unchanged commit.
# The CLI pins its own Playwright and browser builds, so this one version fixes the whole toolchain.
# Read by the scripts that source this file.
# shellcheck disable=SC2034
bmfp_playwright_cli_package="@playwright/cli@${BUCKMERCE_PLAID_PLAYWRIGHT_CLI_VERSION:-0.1.22}"

# PHP_INI_SCAN_DIR of the disposable sites' built-in web server (browser and Sandbox suites): PHP's
# own scan directory, then tests/fixtures/php-server (OPcache JIT off: setup-php's default tracing JIT
# crashes PHP 8.2's built-in server workers), then BUCKMERCE_PLAID_E2E_PHP_INI_DIR when set (loaded
# last, so it can override the fixture, e.g. to reproduce an engine configuration on purpose).
bmfp_server_ini_scan() {
    local scan="${PHP_INI_SCAN_DIR:-}:$bmfp_base_dir/tests/fixtures/php-server"
    if [[ -n "${BUCKMERCE_PLAID_E2E_PHP_INI_DIR:-}" ]]; then
        scan="${scan}:${BUCKMERCE_PLAID_E2E_PHP_INI_DIR}"
    fi
    printf '%s' "$scan"
}
