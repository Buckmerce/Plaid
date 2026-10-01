#!/usr/bin/env bash
# Documentation integrity gate: the engineering documentation AGENTS.md requires every
# contributor to read must be version-controlled, and every relative Markdown link in the
# tracked documentation must point to a tracked file. Prevents the handbook from silently
# disappearing from the repository again (it was once git-ignored).
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
cd "$base_dir"
failures=0
fail() { printf 'DOCS CHECK FAILED: %s\n' "$*" >&2; failures=$((failures + 1)); }

mandatory=(
    AGENTS.md
    CHANGELOG.md
    README.md
    readme.txt
    docs/README.md
    docs/DEVELOPMENT_HANDBOOK.md
    docs/ARCHITECTURE.md
    docs/API_REFERENCE.md
    docs/PAYMENT_LIFECYCLE.md
    docs/STATE_MACHINE.md
    docs/CONCURRENCY_IDEMPOTENCY.md
    docs/WEBHOOKS_AND_EVENTS.md
    docs/DATA_MODEL.md
    docs/SECURITY.md
    docs/OBSERVABILITY_SUPPORT.md
    docs/TESTING_QA.md
    docs/CI_CD_RELEASE.md
    docs/RUNBOOKS.md
    docs/PRIVACY_COMPLIANCE.md
    docs/WOO_COMMERCE_INTEGRATION.md
    docs/api/README.md
    docs/api/PLAID_TRANSFER.md
    docs/api/WOOCOMMERCE.md
    docs/api/WORDPRESS.md
    docs/api/ACTION_SCHEDULER.md
    docs/adr/README.md
)
for file in "${mandatory[@]}"; do
    git ls-files --error-unmatch -- "$file" >/dev/null 2>&1 || fail "mandatory documentation is not tracked by Git: $file"
done

# Every ADR listed in the ADR index is tracked.
while IFS= read -r adr; do
    git ls-files --error-unmatch -- "docs/adr/$adr" >/dev/null 2>&1 || fail "ADR listed in docs/adr/README.md is not tracked: docs/adr/$adr"
done < <(grep -oE '[0-9]{4}-[a-z0-9-]+\.md' docs/adr/README.md | sort -u)

# Relative links in tracked Markdown resolve to tracked files (external URLs and anchors are skipped).
tracked=$(git ls-files)
while IFS= read -r doc; do
    dir=$(dirname "$doc")
    while IFS= read -r target; do
        target=${target%%#*}
        [[ -z "$target" ]] && continue
        case "$target" in http://* | https://* | mailto:* | /*) continue ;; esac
        resolved=$(realpath -m --relative-to="$base_dir" "$dir/$target")
        [[ "$resolved" == docs/api/plaid-mirror/* ]] && { fail "$doc links into the untracked Plaid mirror: $target"; continue; }
        if ! grep -Fxq "$resolved" <<<"$tracked" && ! grep -q "^$resolved/" <<<"$tracked"; then
            fail "$doc links to a missing or untracked file: $target"
        fi
    done < <(grep -oE '\]\([^) ]+\)' "$doc" | sed -E 's/^\]\(//; s/\)$//')
done < <(git ls-files -- '*.md')

if [[ "$failures" -gt 0 ]]; then
    printf 'Documentation check: %d problem(s).\n' "$failures" >&2
    exit 1
fi
printf 'Documentation check passed: %d mandatory documents tracked, relative links resolve.\n' "${#mandatory[@]}"
