#!/usr/bin/env bash
# Identity gate. The plugin's only identity is Buckmerce: slug
# buckmerce-plaid, namespace Buckmerce\Plaid, prefixes buckmerce_plaid_ / bmfp_ / _bmfp_.
# The pre-release working name and its short prefix must never come back — no aliases, hooks,
# meta keys, table names, comments or documentation. This gate fails when a former identifier
# appears in
#   1. the repository (every tracked or untracked-but-not-ignored file: its name and content);
#   2. any archive given as an argument (release ZIP, tarball): entry names and content.
# Git history, ignored local files and the name of the checkout directory are not scanned.
# Usage: bash scripts/check-identity.sh [archive.zip|archive.tar[.gz] ...]
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
cd "$base_dir"
failures=0
fail() { printf 'IDENTITY CHECK FAILED: %s\n' "$*" >&2; failures=$((failures + 1)); }

# Assembled from fragments so that this file does not contain the former identifiers itself.
former_brand='pay''[ _-]?''bridge'
former_prefix='pb''fp'
pattern="${former_brand}|${former_prefix}"

# scan <label> <directory> <newline-separated relative file list>
scan() {
    local label=$1 dir=$2 files=$3 names hits
    names=$(grep -E -i -- "$pattern" <<<"$files" || true)
    if [[ -n "$names" ]]; then
        fail "$label: file names carry a former identifier:"
        head -n 20 <<<"$names" | sed 's/^/    /' >&2
    fi
    # -a: compiled translations (.mo) and other binaries are scanned too.
    hits=$(cd "$dir" && tr '\n' '\0' <<<"$files" | xargs -0 -r grep -a -H -E -i -n -o -- "$pattern" 2>/dev/null | cut -c1-200 || true)
    if [[ -n "$hits" ]]; then
        fail "$label: $(wc -l <<<"$hits") former identifier(s) in file content (first 30 shown):"
        head -n 30 <<<"$hits" | sed 's/^/    /' >&2
    fi
}

# 1. The repository.
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    repo_files=$(git ls-files -co --exclude-standard | while IFS= read -r file; do [[ -f "$file" ]] && printf '%s\n' "$file"; done)
else
    repo_files=$(find . -type f \
        -not -path './.git/*' -not -path './node_modules/*' -not -path './vendor/*' -not -path './dist/*' \
        -not -path './output/*' -not -path './.parcel-cache/*' -not -path './assets/*' -not -path './.idea/*' \
        -not -name '.env' -not -name '*.zip' | sed 's|^\./||' | sort)
fi
scan 'repository' "$base_dir" "$repo_files"
repo_count=$(wc -l <<<"$repo_files")

# 2. Archives (the release ZIP).
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
archives=0
for archive in "$@"; do
    [[ -f "$archive" ]] || { fail "missing archive $archive"; continue; }
    target="$work/$archives"
    mkdir -p "$target"
    case "$archive" in
        *.zip) unzip -q "$archive" -d "$target" ;;
        *.tar | *.tar.gz | *.tgz) tar -xf "$archive" -C "$target" ;;
        *) fail "unsupported archive type: $archive"; continue ;;
    esac
    if grep -E -i -q -- "$pattern" <<<"$(basename "$archive")"; then
        fail "the archive name carries a former identifier: $(basename "$archive")"
    fi
    scan "$(basename "$archive")" "$target" "$(cd "$target" && find . -type f | sed 's|^\./||' | sort)"
    archives=$((archives + 1))
done

if [[ "$failures" -gt 0 ]]; then
    printf 'Identity check: %d problem(s). Use the Buckmerce namespace and prefixes.\n' "$failures" >&2
    exit 1
fi
printf 'Identity check passed: 0 former identifiers in %d repository files' "$repo_count"
if [[ "$archives" -gt 0 ]]; then
    printf ' and %d archive(s)' "$archives"
fi
printf '.\n'
