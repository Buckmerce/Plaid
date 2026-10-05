#!/usr/bin/env bash
# Source-distribution audit: fails when what the repository distributes could leak credentials
# or local state. Checks
#   1. the source archive of HEAD (`git archive`, what GitHub serves as "Source code") and any
#      archives given as arguments (release ZIP, tarballs) for forbidden files: .env files,
#      Playwright traces, IDE state, local caches, dependency and build directories, logs, dumps;
#   2. every tracked file for credential patterns (private keys, GitHub/AWS/ngrok tokens,
#      Plaid secrets assigned to secret-like keys);
#   3. .env.example contains placeholders only;
#   4. when a local .env exists: none of its values appears in any tracked file or anywhere in
#      the Git history. Only variable NAMES are ever printed, never values.
# Usage: bash scripts/audit-source.sh [archive.zip|archive.tar[.gz] ...]
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
cd "$base_dir"
failures=0
fail() { printf 'SOURCE AUDIT FAILED: %s\n' "$*" >&2; failures=$((failures + 1)); }

# Compiled assets belong in the release ZIP and must always be generated from resources/.
[[ -z "$(git ls-files -- assets)" ]] || fail 'assets/ contains tracked build output'
git check-ignore --no-index -q assets/buckmerce-mark.svg || fail 'assets/ must be ignored by Git'
[[ -z "$(git ls-files -ci --exclude-standard)" ]] || fail 'tracked files match ignore rules'

forbidden='(^|/)(\.env(\.[A-Za-z0-9_-]+)?|\.idea|\.vscode|\.parcel-cache|\.cache|\.playwright-cli|node_modules|\.phpunit\.cache)(/|$)|^([^/]+/)?(vendor|output|test-results|playwright-report|coverage|dist)/|(^|/)\.phpunit\.result\.cache$|\.trace\.zip$|(^|/)trace\.zip$|\.(log|sql|sql\.gz|dump|sqlite3?|bak|swp|pem|key|p12|pfx)$|(^|/)(\.DS_Store|Thumbs\.db|auth\.json|credentials\.json|secrets\.json)$'
allowed='(^|/)\.env\.example$'

audit_listing() {
    local label=$1 listing=$2 hits
    hits=$(grep -E "$forbidden" <<<"$listing" | grep -Ev "$allowed" || true)
    if [[ -n "$hits" ]]; then
        fail "$label contains files that must never be distributed:"
        head -n 20 <<<"$hits" | sed 's/^/    /' >&2
    fi
}

# 1. Source archive of HEAD and the given archives.
audit_listing 'git archive HEAD' "$(git archive --format=tar HEAD | tar -t)"
for archive in "$@"; do
    [[ -f "$archive" ]] || { fail "missing archive $archive"; continue; }
    case "$archive" in
        *.zip) audit_listing "$(basename "$archive")" "$(unzip -Z1 "$archive")" ;;
        *.tar | *.tar.gz | *.tgz) audit_listing "$(basename "$archive")" "$(tar -tf "$archive")" ;;
        *) fail "unsupported archive type: $archive" ;;
    esac
done

# 2. Credential patterns in tracked files (vendor-prefixed and lock files hold no credentials but are scanned too).
patterns='-----BEGIN ([A-Z]+ )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{40,}|AKIA[0-9A-Z]{16}|(secret|SECRET|Secret)[A-Za-z_]*["'"'"']?[[:space:]]*[:=][>]?[[:space:]]*["'"'"']?[0-9a-f]{30}\b|NGROK_AUTHTOKEN[[:space:]]*=[[:space:]]*[0-9A-Za-z_]{20,}'
# Vendored library documentation (vendor-prefixed/**/*.md, e.g. firebase/php-jwt's README with its
# published sample RSA key) is third-party text, never shipped in the release ZIP, and not scanned.
secret_hits=$(git grep -I -l -E -e "$patterns" -- . ':!*.lock' ':!package-lock.json' ':!vendor-prefixed/**/*.md' || true)
if [[ -n "$secret_hits" ]]; then
    fail 'tracked files contain something that looks like a credential:'
    sed 's/^/    /' <<<"$secret_hits" >&2
fi

# 3. .env.example has placeholders only.
if [[ -f .env.example ]]; then
    while IFS='=' read -r name value; do
        [[ "$name" =~ ^[A-Z0-9_]+$ ]] || continue
        value=${value%%#*}
        value=$(sed -E 's/^[[:space:]"'"'"']+|[[:space:]"'"'"']+$//g' <<<"$value")
        case "$name" in
            *SECRET* | *PASSWORD | *TOKEN* | *CLIENT_ID)
                case "$value" in
                    '' | user_good | pass_good | your-* | '<'*'>' | changeme) ;;
                    *) fail ".env.example must not contain a real value for $name" ;;
                esac
                ;;
        esac
    done < <(grep -E '^[A-Z0-9_]+=' .env.example)
fi

# 4. Credential values of the local .env never reached Git (tracked files or history). Hostnames
#    and database names are configuration, not credentials, and are not checked.
if [[ -f .env ]]; then
    while IFS='=' read -r name value; do
        [[ "$name" =~ ^[A-Za-z0-9_]+$ ]] || continue
        [[ "$name" =~ (SECRET|PASSWORD|TOKEN|CLIEND_ID|CLIENT_ID|KEY) ]] || continue
        value=$(sed -E 's/^[[:space:]"'"'"']+|[[:space:]"'"'"']+$//g' <<<"$value")
        # Short or well-known test values (user_good, localhost…) are not credentials.
        [[ ${#value} -ge 12 ]] || continue
        case "$value" in localhost* | 127.0.0.1* | user_good | pass_good) continue ;; esac
        if git grep -I -q -F -e "$value" -- . 2>/dev/null; then
            fail "the value of .env variable $name is committed in a tracked file"
        fi
        if [[ -n "$(git log --all --format=%H -S"$value" 2>/dev/null | head -n 1)" ]]; then
            fail "the value of .env variable $name appears in the Git history: rotate it"
        fi
    done < <(grep -E '^[A-Za-z0-9_]+=' .env)
fi

if [[ "$failures" -gt 0 ]]; then
    printf 'Source audit: %d problem(s).\n' "$failures" >&2
    exit 1
fi
printf 'Source audit passed: no credentials, .env files, traces, IDE state or local caches are distributed.\n'
