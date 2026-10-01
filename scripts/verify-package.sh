#!/usr/bin/env bash
# Verifies a built release ZIP before it is tested or published:
#   - required runtime files present, including EVERY PHP class under src/ (source/package parity);
#   - compiled assets are byte-identical to a fresh build of the current sources (no stale JS/CSS);
#   - no development files, credentials, source maps or local filesystem paths;
#   - one root directory named after the slug, the ZIP named <slug>-<version>.zip, and no former
#     identifier in the package (scripts/check-identity.sh);
#   - every PHP file lints;
#   - prints and records the SHA-256 (dist/<zip>.sha256) so the tested artifact is the released one.
# Usage: bash scripts/verify-package.sh [path/to/buckmerce-for-plaid-X.Y.Z.zip]
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
version=$(grep -m1 '^ \* Version:' "$base_dir/buckmerce-for-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
zip=${1:-"$base_dir/dist/buckmerce-for-plaid-$version.zip"}
slug=buckmerce-for-plaid
[[ -f "$zip" ]] || { printf 'Missing ZIP: %s\n' "$zip" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
unzip -q "$zip" -d "$work/zip"
listing=$(cd "$work/zip" && find . -type f | sed 's|^\./||' | sort)
fail() { printf 'PACKAGE CHECK FAILED: %s\n' "$*" >&2; exit 1; }

# 1. Required runtime files and source/package parity. The ZIP has one root directory, the slug.
roots=$(cut -d/ -f1 <<<"$listing" | sort -u)
[[ "$roots" == "$slug" ]] || fail "the ZIP root must be the single directory $slug/ (found: $(tr '\n' ' ' <<<"$roots"))"
[[ "$(basename "$zip")" == "$slug-$version.zip" ]] || fail "the ZIP must be named $slug-$version.zip"
for required in "$slug/$slug.php" "$slug/readme.txt" "$slug/uninstall.php" "$slug/LICENSE" \
    "$slug/vendor-prefixed/autoload.php" "$slug/vendor-prefixed/firebase/php-jwt/src/JWT.php" "$slug/vendor-prefixed/firebase/php-jwt/LICENSE" \
    "$slug/languages/$slug.pot" "$slug/assets/admin-settings.css" "$slug/assets/payment-page.css" \
    "$slug/assets/build/blocks.js" "$slug/assets/build/payment-page.js" "$slug/assets/build/admin-settings.js" "$slug/assets/images/buckmerce-mark.svg"; do
    grep -Fxq "$required" <<<"$listing" || fail "missing runtime file $required"
done
while IFS= read -r source; do
    grep -Fxq "$slug/$source" <<<"$listing" || fail "source file not packaged: $source"
done < <(cd "$base_dir" && find src -type f -name '*.php' | sort)
# Every bundled translation file (source .po and the compiled files WordPress loads) is shipped.
while IFS= read -r translation; do
    grep -Fxq "$slug/$translation" <<<"$listing" || fail "translation file not packaged: $translation"
done < <(cd "$base_dir" && find languages -type f \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' -o -name '*.json' \) | sort)
packaged_src=$(grep -c "^$slug/src/.*\.php$" <<<"$listing" || true)
source_src=$(cd "$base_dir" && find src -type f -name '*.php' | wc -l)
[[ "$packaged_src" -eq "$source_src" ]] || fail "src/ has $source_src PHP files but the ZIP has $packaged_src"

# 2. Nothing that belongs to development.
if grep -Eq "(^|/)(\.git|\.github|\.idea|\.vscode|\.playwright-cli|\.parcel-cache|tests|docs|scripts|tools|node_modules|vendor|dist|resources|output|coverage|test-results|playwright-report)(/|$)|(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml\.dist|phpstan\.neon|phpcs\.xml\.dist|tsconfig\.json|\.env[^/]*|\.phpunit\.result\.cache|AGENTS\.md|CLAUDE\.md|README\.md|CHANGELOG\.md)$|\.(map|log|zip|tar|gz|sql|bak|orig|swp)$" <<<"$listing"; then
    grep -E "(^|/)(\.git|\.github|\.idea|tests|docs|scripts|node_modules|vendor|dist|resources)(/|$)|\.env|\.map$" <<<"$listing" | head -5 >&2
    fail 'development files found in the ZIP'
fi
if grep -rIl --include='*.js' --include='*.css' 'sourceMappingURL' "$work/zip" >/dev/null 2>&1; then
    fail 'compiled assets reference source maps'
fi
if grep -rIlE '(/home/[a-z0-9_-]+/|/opt/lampp|/tmp/buckmerce-|/Users/[A-Za-z0-9_-]+/)' "$work/zip" >/dev/null 2>&1; then
    grep -rIlE '(/home/[a-z0-9_-]+/|/opt/lampp|/tmp/buckmerce-|/Users/[A-Za-z0-9_-]+/)' "$work/zip" | head -5 >&2
    fail 'local filesystem paths found in shipped files'
fi
if grep -rIlE '(PLAID-SECRET|secret)["'"'"']?[[:space:]]*[:=>]+[[:space:]]*["'"'"'][0-9a-f]{30}["'"'"']' "$work/zip" >/dev/null 2>&1; then
    fail 'something that looks like a Plaid secret is in the ZIP'
fi

# 3. Compiled assets equal a fresh build of the current sources.
if [[ "${BUCKMERCE_PLAID_SKIP_ASSET_PARITY:-0}" != 1 ]]; then
    mkdir -p "$work/build"
    cp -R "$base_dir/resources" "$base_dir/package.json" "$base_dir/tsconfig.json" "$work/build/"
    mkdir -p "$work/build/scripts" && cp "$base_dir/scripts/copy-images.sh" "$work/build/scripts/"
    ln -s "$base_dir/node_modules" "$work/build/node_modules"
    (cd "$work/build" && npx --no-install parcel build --no-content-hash --no-cache >/dev/null 2>&1 && bash scripts/copy-images.sh >/dev/null)
    while IFS= read -r asset; do
        cmp -s "$work/build/$asset" "$work/zip/$slug/$asset" || fail "stale compiled asset: $asset differs from a fresh build"
    done < <(cd "$work/build" && find assets -type f | sort)
    fresh_count=$(cd "$work/build" && find assets -type f | wc -l)
    zip_count=$(grep -c "^$slug/assets/" <<<"$listing" || true)
    [[ "$fresh_count" -eq "$zip_count" ]] || fail "the ZIP has $zip_count asset files, a fresh build has $fresh_count"
fi

# 4. No former identifier in the package or the repository (scripts/check-identity.sh).
bash "$base_dir/scripts/check-identity.sh" "$zip" >/dev/null || fail 'former identifiers found (run: bash scripts/check-identity.sh <zip>)'

# 5. Every PHP file lints.
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "PHP syntax error in ${file#"$work/zip/"}"
done < <(find "$work/zip" -name '*.php' -print0)

sha=$(sha256sum "$zip" | cut -d' ' -f1)
printf '%s  %s\n' "$sha" "$(basename "$zip")" > "$zip.sha256"
printf 'Package verified: %s (%d files)\nSHA-256: %s\n' "$(basename "$zip")" "$(wc -l <<<"$listing")" "$sha"
