#!/usr/bin/env bash
# Builds dist/buckmerce-for-plaid-<version>.zip from the current source tree.
# The ZIP is always rebuilt from scratch; a stale archive is never reused.
# The archive is reproducible: the same source and SOURCE_DATE_EPOCH (default: the HEAD commit
# time) always give byte-identical ZIPs (fixed timestamps, permissions and entry order).
# BUCKMERCE_PLAID_DIST_DIR writes the ZIP elsewhere (used to compare a rebuild with a released artifact).
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="buckmerce-for-plaid"
MAIN_FILE="$BASE_DIR/$SLUG.php"

HEADER_VERSION="$(grep -m1 '^ \* Version:' "$MAIN_FILE" | sed -E 's/^ \* Version:[[:space:]]*//')"
CONSTANT_VERSION="$(grep -m1 "define( 'BUCKMERCE_PLAID_VERSION'" "$MAIN_FILE" | sed -E "s/.*'BUCKMERCE_PLAID_VERSION',[[:space:]]*'([^']+)'.*/\1/")"
STABLE_TAG="$(grep -m1 '^Stable tag:' "$BASE_DIR/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//')"
PACKAGE_VERSION="$(sed -nE 's/^[[:space:]]*"version":[[:space:]]*"([^"]+)".*/\1/p' "$BASE_DIR/package.json" | head -1)"
VERSION="${1:-$HEADER_VERSION}"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$ ]]; then
    echo "Invalid version: $VERSION" >&2
    exit 1
fi
# Refuse to build when ANY version source differs from the requested version.
# shellcheck disable=SC2055
if [[ "$VERSION" != "$HEADER_VERSION" || "$VERSION" != "$CONSTANT_VERSION" || "$VERSION" != "$STABLE_TAG" || "$VERSION" != "$PACKAGE_VERSION" ]]; then
    echo "Version mismatch: requested=$VERSION header=$HEADER_VERSION BUCKMERCE_PLAID_VERSION=$CONSTANT_VERSION readme=$STABLE_TAG package.json=$PACKAGE_VERSION" >&2
    exit 1
fi

for asset in assets/admin-settings.css assets/payment-page.css assets/build/blocks.js assets/build/payment-page.js assets/build/admin-settings.js assets/images/buckmerce-mark.svg; do
    if [[ ! -f "$BASE_DIR/$asset" ]]; then
        echo "Missing compiled asset $asset. Run 'npm run build' first." >&2
        exit 1
    fi
done
if [[ ! -f "$BASE_DIR/vendor-prefixed/autoload.php" || ! -f "$BASE_DIR/vendor-prefixed/firebase/php-jwt/src/JWT.php" ]]; then
    echo "Missing prefixed runtime dependencies. Run 'composer install' first." >&2
    exit 1
fi

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
PLUGIN_DIR="$STAGE_DIR/$SLUG"
DIST_DIR="${BUCKMERCE_PLAID_DIST_DIR:-$BASE_DIR/dist}"
mkdir -p "$PLUGIN_DIR" "$DIST_DIR"
DIST_DIR="$(cd "$DIST_DIR" && pwd)"

cp "$MAIN_FILE" "$BASE_DIR/readme.txt" "$BASE_DIR/uninstall.php" "$BASE_DIR/LICENSE" "$PLUGIN_DIR/"
cp -a "$BASE_DIR/src" "$BASE_DIR/assets" "$BASE_DIR/languages" "$BASE_DIR/vendor-prefixed" "$PLUGIN_DIR/"
find "$PLUGIN_DIR/languages" -name '.gitkeep' -delete
# Runtime needs only the prefixed sources, autoloader and licenses of bundled libraries.
find "$PLUGIN_DIR/vendor-prefixed" \( -name 'composer.json' -o -name 'README.md' -o -name 'CHANGELOG.md' \) -not -path '*/vendor-prefixed/composer/*' -delete

# Reproducible archive: normalized permissions and timestamps, sorted entries, no extra
# attributes (uid/gid, extended timestamps). ZIP stores local time, so zip runs in UTC.
EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$BASE_DIR" log -1 --format=%ct 2>/dev/null || true)}"
[[ "$EPOCH" =~ ^[0-9]+$ ]] || EPOCH=1767225600
find "$PLUGIN_DIR" -type d -exec chmod 0755 {} +
find "$PLUGIN_DIR" -type f -exec chmod 0644 {} +
find "$PLUGIN_DIR" -exec touch -h -d "@$EPOCH" {} +

ZIP="$DIST_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(
    cd "$STAGE_DIR"
    find "$SLUG" -print | LC_ALL=C sort | TZ=UTC zip -qX -@ "$ZIP"
)

# Package gate: required runtime files present, development files absent.
LISTING="$(unzip -Z1 "$ZIP")"
for required in "$SLUG/$SLUG.php" "$SLUG/readme.txt" "$SLUG/uninstall.php" "$SLUG/src/Plugin.php" "$SLUG/vendor-prefixed/autoload.php" "$SLUG/assets/build/payment-page.js" "$SLUG/languages/$SLUG.pot"; do
    if ! grep -Fxq "$required" <<<"$LISTING"; then
        echo "Missing required release file: $required" >&2
        exit 1
    fi
done
if grep -Eq '(^|/)(\.git|\.github|\.idea|\.env|tests|docs|scripts|tools|node_modules|vendor|dist|resources)(/|$)|(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml\.dist|phpstan\.neon|phpcs\.xml\.dist|CLAUDE\.md|AGENTS\.md|README\.md)$' <<<"$LISTING"; then
    echo "Development-only files found in release ZIP." >&2
    exit 1
fi

echo "Created: $ZIP"
