#!/usr/bin/env bash
# Builds dist/buckmerce-plaid-<version>.zip, the WordPress.org distribution, from the current
# source tree. The package is assembled from an allowlist (scripts/lib/package-manifest.sh):
#   empty staging directory → exactly the listed runtime files → allowlist check →
#   normalized permissions and timestamps → ZIP.
# Nothing is ever copied wholesale and pruned afterwards, so a development file cannot slip in.
# The ZIP is always rebuilt from scratch; a stale archive is never reused.
# The archive is reproducible: the same source and SOURCE_DATE_EPOCH (default: the HEAD commit
# time) always give byte-identical ZIPs (fixed timestamps, permissions and entry order).
# BUCKMERCE_PLAID_DIST_DIR writes the ZIP elsewhere (used to compare a rebuild with a released artifact).
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="buckmerce-plaid"
MAIN_FILE="$BASE_DIR/buckmerce-plaid.php"
# shellcheck source=lib/package-manifest.sh
. "$BASE_DIR/scripts/lib/package-manifest.sh"

fail() {
    echo "$*" >&2
    exit 1
}

[[ -f "$MAIN_FILE" ]] || fail "Missing main plugin file $SLUG.php."
HEADER_VERSION="$(grep -m1 '^ \* Version:' "$MAIN_FILE" | sed -E 's/^ \* Version:[[:space:]]*//')"
CONSTANT_VERSION="$(grep -m1 "define( 'BUCKMERCE_PLAID_VERSION'" "$MAIN_FILE" | sed -E "s/.*'BUCKMERCE_PLAID_VERSION',[[:space:]]*'([^']+)'.*/\1/")"
STABLE_TAG="$(grep -m1 '^Stable tag:' "$BASE_DIR/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//')"
PACKAGE_VERSION="$(sed -nE 's/^[[:space:]]*"version":[[:space:]]*"([^"]+)".*/\1/p' "$BASE_DIR/package.json" | head -1)"
VERSION="${1:-$HEADER_VERSION}"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$ ]]; then
    fail "Invalid version: $VERSION"
fi
# Refuse to build when ANY version source differs from the requested version.
# shellcheck disable=SC2055
if [[ "$VERSION" != "$HEADER_VERSION" || "$VERSION" != "$CONSTANT_VERSION" || "$VERSION" != "$STABLE_TAG" || "$VERSION" != "$PACKAGE_VERSION" ]]; then
    fail "Version mismatch: requested=$VERSION header=$HEADER_VERSION BUCKMERCE_PLAID_VERSION=$CONSTANT_VERSION readme=$STABLE_TAG package.json=$PACKAGE_VERSION"
fi

for asset in assets/admin-settings.css assets/payment-page.css assets/build/blocks.js assets/build/payment-page.js assets/build/admin-settings.js assets/images/buckmerce-mark.svg; do
    [[ -f "$BASE_DIR/$asset" ]] || fail "Missing compiled asset $asset. Run 'npm run build' first."
done
if [[ ! -f "$BASE_DIR/vendor-prefixed/autoload.php" || ! -f "$BASE_DIR/vendor-prefixed/firebase/php-jwt/src/JWT.php" ]]; then
    fail "Missing prefixed runtime dependencies. Run 'composer install' first."
fi

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
PLUGIN_DIR="$STAGE_DIR/$SLUG"
DIST_DIR="${BUCKMERCE_PLAID_DIST_DIR:-$BASE_DIR/dist}"
mkdir -p "$PLUGIN_DIR" "$DIST_DIR"
DIST_DIR="$(cd "$DIST_DIR" && pwd)"

# 1. Stage exactly the allowlisted runtime files. The staging directory starts empty.
stage() {
    local relative=$1
    mkdir -p "$(dirname "$PLUGIN_DIR/$relative")"
    cp "$BASE_DIR/$relative" "$PLUGIN_DIR/$relative"
}
for entry in "${bmfp_release_top_level[@]}"; do
    if [[ -f "$BASE_DIR/$entry" ]]; then
        bmfp_release_is_allowed "$entry" || fail "Top-level file $entry is not in the release allowlist."
        stage "$entry"
    elif [[ ! -d "$BASE_DIR/$entry" ]]; then
        fail "Missing release entry: $entry"
    fi
done
cd "$BASE_DIR"
if [[ -n "$(find "${bmfp_release_source_dirs[@]}" -type l -print -quit)" ]]; then
    fail "Symbolic links are not packaged: $(find "${bmfp_release_source_dirs[@]}" -type l | head -n 5 | tr '\n' ' ')"
fi
while IFS= read -r relative; do
    if bmfp_release_is_allowed "$relative"; then
        stage "$relative"
    elif ! bmfp_release_is_not_shipped "$relative"; then
        fail "$relative is neither a release file nor a known non-runtime file. Decide in scripts/lib/package-manifest.sh."
    fi
done < <(find "${bmfp_release_source_dirs[@]}" -type f | LC_ALL=C sort)

# 2. The staged tree holds the required files, the expected top-level entries and nothing else.
for required in "${bmfp_release_required[@]}"; do
    [[ -f "$PLUGIN_DIR/$required" ]] || fail "Missing required release file: $required"
done
EXPECTED_TOP_LEVEL="$(printf '%s\n' "${bmfp_release_top_level[@]}" | LC_ALL=C sort)"
STAGED_TOP_LEVEL="$(cd "$PLUGIN_DIR" && find . -mindepth 1 -maxdepth 1 | sed 's|^\./||' | LC_ALL=C sort)"
[[ "$STAGED_TOP_LEVEL" == "$EXPECTED_TOP_LEVEL" ]] || fail "Unexpected top-level entries in the package: $(tr '\n' ' ' <<<"$STAGED_TOP_LEVEL")"
while IFS= read -r relative; do
    bmfp_release_is_allowed "$relative" || fail "Staged file outside the release allowlist: $relative"
done < <(cd "$PLUGIN_DIR" && find . -type f | sed 's|^\./||' | LC_ALL=C sort)

# 3. Reproducible archive: normalized permissions and timestamps, sorted entries, no extra
# attributes (uid/gid, extended timestamps). ZIP stores local time, so zip runs in UTC.
EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$BASE_DIR" log -1 --format=%ct 2>/dev/null || true)}"
[[ "$EPOCH" =~ ^[0-9]+$ ]] || EPOCH=1767225600
find "$PLUGIN_DIR" -type d -exec chmod 0755 {} +
find "$PLUGIN_DIR" -type f -exec chmod 0644 {} +
find "$PLUGIN_DIR" -exec touch -h -d "@$EPOCH" {} +

# 4. dist/ holds the current artifact only: earlier ZIPs and their hashes are removed (in the
# project's own dist/; another output directory only loses the file being rebuilt), and the hash
# of this ZIP is recorded again by scripts/verify-package.sh.
ZIP="$DIST_DIR/$SLUG-$VERSION.zip"
if [[ -z "${BUCKMERCE_PLAID_DIST_DIR:-}" ]]; then
    find "$DIST_DIR" -maxdepth 1 -type f \( -name '*.zip' -o -name '*.zip.sha256' \) -delete
fi
rm -f "$ZIP" "$ZIP.sha256"
(
    cd "$STAGE_DIR"
    find "$SLUG" -print | LC_ALL=C sort | TZ=UTC zip -qX -@ "$ZIP"
)

# 5. Package gate on the archive itself: one root directory, allowlisted files only.
LISTING="$(unzip -Z1 "$ZIP")"
while IFS= read -r entry; do
    case "$entry" in
        "$SLUG"/*) ;;
        *) fail "ZIP entry outside the $SLUG/ root directory: $entry" ;;
    esac
    [[ "$entry" == */ ]] && continue
    bmfp_release_is_allowed "${entry#"$SLUG"/}" || fail "ZIP entry outside the release allowlist: $entry"
done <<<"$LISTING"
for required in "${bmfp_release_required[@]}"; do
    grep -Fxq "$SLUG/$required" <<<"$LISTING" || fail "Missing required release file: $required"
done

echo "Created: $ZIP"
