#!/usr/bin/env bash
# Known-vulnerability audit of the locked dependencies: the Composer runtime dependencies shipped
# in the ZIP, the Composer development dependencies and the npm build toolchain of the shipped assets.
#
# Unlike the other gates, the result depends on the day of the run and not on the commit: an
# advisory published today turns a tree that was green yesterday red. The audit therefore blocks
# only where somebody is about to act on it (BUCKMERCE_PLAID_EXTERNAL_CHECKS):
#   strict (default)  any advisory, or an audit that could not run, fails: releases (release.yml),
#                     the daily audit of master (dependency-audit.yml) and local runs.
#   warn              the findings are printed and reported as a warning, the exit status is 0:
#                     pushes and pull requests, where a red run must mean the commit is broken.
# Usage: bash scripts/audit-dependencies.sh
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
cd "$base_dir"
mode=${BUCKMERCE_PLAID_EXTERNAL_CHECKS:-strict}
[[ "$mode" == strict || "$mode" == warn ]] || { printf 'BUCKMERCE_PLAID_EXTERNAL_CHECKS must be "strict" or "warn".\n' >&2; exit 64; }
failed=0

# audit <label> <command...>
audit() {
    local label=$1
    shift
    printf '== %s\n' "$label"
    if "$@"; then
        return 0
    fi
    failed=$((failed + 1))
    if [[ "$mode" == warn && -n "${GITHUB_ACTIONS:-}" ]]; then
        printf '::warning title=Dependency audit::%s: advisories were reported (or the audit could not run). Not blocking here; it blocks a release and fails the daily audit.\n' "$label"
    fi
}

audit 'Composer runtime dependencies (shipped in the ZIP)' composer audit --locked --no-dev
audit 'Composer development dependencies' composer audit --locked
audit 'npm dependencies (build toolchain of the shipped assets)' npm audit --audit-level=high

if [[ "$failed" -eq 0 ]]; then
    printf 'Dependency audit passed: no known advisories in the locked dependencies.\n'
    exit 0
fi
if [[ "$mode" == warn ]]; then
    printf 'Dependency audit: %d of 3 audits reported advisories; reported as a warning (BUCKMERCE_PLAID_EXTERNAL_CHECKS=warn).\n' "$failed" >&2
    exit 0
fi
printf 'Dependency audit failed: %d of 3 audits reported advisories.\n' "$failed" >&2
exit 1
