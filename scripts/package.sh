#!/usr/bin/env bash
# Builds dist/paybridge-for-plaid-<version>.zip from the current source tree.
# The ZIP is always rebuilt from scratch; a stale archive is never reused.
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="paybridge-for-plaid"
MAIN_FILE="$BASE_DIR/$SLUG.php"

HEADER_VERSION="$(grep -m1 '^ \* Version:' "$MAIN_FILE" | sed -E 's/^ \* Version:[[:space:]]*//')"
CONSTANT_VERSION="$(grep -m1 "define( 'PAYBRIDGE_PLAID_VERSION'" "$MAIN_FILE" | sed -E "s/.*'PAYBRIDGE_PLAID_VERSION',[[:space:]]*'([^']+)'.*/\1/")"
STABLE_TAG="$(grep -m1 '^Stable tag:' "$BASE_DIR/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//')"
VERSION="${1:-$HEADER_VERSION}"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Invalid version: $VERSION" >&2
    exit 1
fi
if [[ "$VERSION" != "$HEADER_VERSION" || "$VERSION" != "$CONSTANT_VERSION" || "$VERSION" != "$STABLE_TAG" ]]; then
    echo "Version mismatch: requested=$VERSION header=$HEADER_VERSION PAYBRIDGE_PLAID_VERSION=$CONSTANT_VERSION readme=$STABLE_TAG" >&2
    exit 1
fi

for asset in assets/admin-settings.css assets/payment-page.css assets/build/blocks.js assets/build/payment-page.js assets/build/admin-settings.js assets/images/paybridge-mark.svg; do
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
mkdir -p "$PLUGIN_DIR" "$BASE_DIR/dist"

cp "$MAIN_FILE" "$BASE_DIR/readme.txt" "$BASE_DIR/uninstall.php" "$BASE_DIR/LICENSE" "$PLUGIN_DIR/"
cp -a "$BASE_DIR/src" "$BASE_DIR/assets" "$BASE_DIR/languages" "$BASE_DIR/vendor-prefixed" "$PLUGIN_DIR/"
find "$PLUGIN_DIR/languages" -name '.gitkeep' -delete
# Runtime needs only the prefixed sources, autoloader and licenses of bundled libraries.
find "$PLUGIN_DIR/vendor-prefixed" \( -name 'composer.json' -o -name 'README.md' -o -name 'CHANGELOG.md' \) -not -path '*/vendor-prefixed/composer/*' -delete

ZIP="$BASE_DIR/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(
    cd "$STAGE_DIR"
    zip -qrX "$ZIP" "$SLUG"
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
