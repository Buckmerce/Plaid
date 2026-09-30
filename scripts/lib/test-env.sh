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
