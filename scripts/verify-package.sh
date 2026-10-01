#!/usr/bin/env bash
# Verifies a built release ZIP before it is tested, published or submitted to WordPress.org:
#   1. structure: the ZIP is named <slug>-<version>.zip and has exactly one root directory, the
#      slug, with exactly the top-level entries of the release manifest;
#   2. allowlist: every file is a runtime file of scripts/lib/package-manifest.sh — hidden files,
#      development configuration, tests, scripts, front-end sources, node_modules, vendor/,
#      nested archives, logs, dumps, IDE files, source maps and .env files are rejected by name
#      first (clear message) and by the allowlist in any case;
#   3. source/package parity: every runtime file of the repository is in the ZIP and is
#      byte-identical (src/, translations, vendor-prefixed/, the top-level files);
#   4. the shipped composer.json is valid and declares the bundled library, PHP, the license and
#      the Strauss prefixes; the bundled library is prefixed and ships its license;
#   5. no source maps, local filesystem paths, credentials or values of the local .env;
#   6. compiled assets are byte-identical to a fresh build of the current sources (no stale JS/CSS);
#   7. identity: no former identifier (scripts/check-identity.sh) and no superseded slug;
#   8. the versions inside the ZIP agree with its name; every PHP file lints;
#   9. prints and records the SHA-256 (dist/<zip>.sha256) so the tested artifact is the released one.
# Usage: bash scripts/verify-package.sh [path/to/buckmerce-plaid-X.Y.Z.zip]
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
# shellcheck source=lib/package-manifest.sh
. "$base_dir/scripts/lib/package-manifest.sh"
version=$(grep -m1 '^ \* Version:' "$base_dir/buckmerce-plaid.php" | sed -E 's/^ \* Version:[[:space:]]*//')
zip=${1:-"$base_dir/dist/buckmerce-plaid-$version.zip"}
slug=buckmerce-plaid
[[ -f "$zip" ]] || { printf 'Missing ZIP: %s\n' "$zip" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
fail() { printf 'PACKAGE CHECK FAILED: %s\n' "$*" >&2; exit 1; }

# 1. Structure. Every entry (directories included) lives under the single root directory.
entries=$(unzip -Z1 "$zip")
roots=$(cut -d/ -f1 <<<"$entries" | LC_ALL=C sort -u)
[[ "$roots" == "$slug" ]] || fail "the ZIP root must be the single directory $slug/ (found: $(tr '\n' ' ' <<<"$roots"))"
grep -Fxq "$slug/" <<<"$entries" || fail "the ZIP root $slug must be a directory"
[[ "$(basename "$zip")" == "$slug-$version.zip" ]] || fail "the ZIP must be named $slug-$version.zip"
unzip -q "$zip" -d "$work/zip"
[[ -z "$(find "$work/zip" -type l -print -quit)" ]] || fail 'the ZIP contains symbolic links'
listing=$(cd "$work/zip" && find . -type f | sed 's|^\./||' | LC_ALL=C sort)
top_level=$(cd "$work/zip/$slug" && find . -mindepth 1 -maxdepth 1 | sed 's|^\./||' | LC_ALL=C sort)
expected_top_level=$(printf '%s\n' "${bmfp_release_top_level[@]}" | LC_ALL=C sort)
[[ "$top_level" == "$expected_top_level" ]] || fail "top-level entries differ from the release manifest: $(tr '\n' ' ' <<<"$top_level")"
for required in "${bmfp_release_required[@]}"; do
    grep -Fxq "$slug/$required" <<<"$listing" || fail "missing runtime file $slug/$required"
done

# 2. Allowlist. Named rejections first, for a message that says what went wrong.
reject() {
    local reason=$1 pattern=$2 hits
    hits=$(grep -E -- "$pattern" <<<"$listing" || true)
    [[ -z "$hits" ]] || fail "$reason in the ZIP: $(head -n 5 <<<"$hits" | tr '\n' ' ')"
}
reject 'hidden files' '(^|/)\.[^/]+'
reject 'environment files' '(^|/)\.env[^/]*$'
reject 'development directories' '(^|/)(tests?|docs|scripts|tools|resources|node_modules|vendor|dist|output|coverage|test-results|playwright-report|build-src)(/|$)'
reject 'development configuration' '(^|/)(composer\.lock|package(-lock)?\.json|tsconfig\.json|phpunit\.xml[^/]*|phpstan\.neon[^/]*|phpcs\.xml[^/]*|\.phpunit\.result\.cache|AGENTS\.md|CLAUDE\.md|README\.md|CHANGELOG\.md)$'
reject 'nested archives' '\.(zip|tar|tgz|gz|bz2|xz|7z|rar|phar)$'
reject 'logs, dumps, backups or temporary files' '\.(log|sql|dump|sqlite3?|db|bak|backup|orig|rej|tmp|temp|swp|swo)$|~$'
reject 'source maps' '\.map$'
reject 'markdown files' '\.md$'
while IFS= read -r file; do
    bmfp_release_is_allowed "${file#"$slug"/}" || fail "file outside the release allowlist (scripts/lib/package-manifest.sh): $file"
done <<<"$listing"

# 3. Source/package parity: every runtime file of the repository is packaged, byte for byte.
same() {
    local relative=$1
    [[ -f "$work/zip/$slug/$relative" ]] || fail "not packaged: $relative"
    cmp -s "$base_dir/$relative" "$work/zip/$slug/$relative" || fail "packaged $relative differs from the source tree (rebuild the ZIP)"
}
for entry in "${bmfp_release_top_level[@]}"; do
    [[ -d "$base_dir/$entry" ]] || same "$entry"
done
while IFS= read -r source; do
    same "$source"
done < <(cd "$base_dir" && find src -type f -name '*.php' | LC_ALL=C sort)
# Every bundled translation file (template, source .po and the compiled files WordPress loads).
while IFS= read -r translation; do
    same "$translation"
done < <(cd "$base_dir" && find languages -type f \( -name '*.pot' -o -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' -o -name '*.json' \) | LC_ALL=C sort)
while IFS= read -r dependency; do
    bmfp_release_is_allowed "$dependency" && same "$dependency"
done < <(cd "$base_dir" && find vendor-prefixed -type f | LC_ALL=C sort)
packaged_src=$(grep -c "^$slug/src/.*\.php$" <<<"$listing" || true)
source_src=$(cd "$base_dir" && find src -type f -name '*.php' | wc -l)
[[ "$packaged_src" -eq "$source_src" ]] || fail "src/ has $source_src PHP files but the ZIP has $packaged_src"
packaged_vendor=$(grep -c "^$slug/vendor-prefixed/" <<<"$listing" || true)
source_vendor=$(cd "$base_dir" && find vendor-prefixed -type f | while IFS= read -r file; do bmfp_release_is_allowed "$file" && printf '%s\n' "$file"; done | wc -l)
[[ "$packaged_vendor" -eq "$source_vendor" ]] || fail "vendor-prefixed/ has $source_vendor runtime files but the ZIP has $packaged_vendor"

# 4. The shipped composer.json describes the bundled dependency, and the dependency is the
#    prefixed, licensed copy only (never vendor/, never the unprefixed namespace).
php -r '
    $composer = json_decode((string) file_get_contents($argv[1]), true);
    $problems = array();
    $expect = static function (bool $condition, string $message) use (&$problems): void {
        if (! $condition) {
            $problems[] = $message;
        }
    };
    $expect(is_array($composer), "not valid JSON");
    $composer = is_array($composer) ? $composer : array();
    $expect("al5dy/buckmerce-plaid" === ($composer["name"] ?? null), "name must be al5dy/buckmerce-plaid");
    $expect("wordpress-plugin" === ($composer["type"] ?? null), "type must be wordpress-plugin");
    $expect("GPL-2.0-or-later" === ($composer["license"] ?? null), "license must be GPL-2.0-or-later");
    $expect(">=8.1" === ($composer["require"]["php"] ?? null), "require.php must be >=8.1");
    $expect(isset($composer["require"]["firebase/php-jwt"]), "require must declare firebase/php-jwt");
    $expect(array("php", "firebase/php-jwt") === array_keys($composer["require"] ?? array()), "require must list exactly php and firebase/php-jwt (every other bundled library needs a release decision)");
    $strauss = $composer["extra"]["strauss"] ?? array();
    $expect("vendor-prefixed" === ($strauss["target_directory"] ?? null), "extra.strauss.target_directory must be vendor-prefixed");
    $expect("Buckmerce\\Plaid\\Vendor\\" === ($strauss["namespace_prefix"] ?? null), "extra.strauss.namespace_prefix");
    $expect(array("firebase/php-jwt") === ($strauss["packages"] ?? null), "extra.strauss.packages must list firebase/php-jwt only");
    foreach ($problems as $problem) {
        fwrite(STDERR, "composer.json: " . $problem . "\n");
    }
    exit(array() === $problems ? 0 : 1);
' "$work/zip/$slug/composer.json" || fail 'the shipped composer.json does not describe the release'
# BUCKMERCE_PLAID_COMPOSER names another Composer command (for example "php composer.phar").
read -r -a composer_command <<<"${BUCKMERCE_PLAID_COMPOSER:-composer}"
if command -v "${composer_command[0]}" >/dev/null 2>&1; then
    mkdir -p "$work/composer"
    cp "$work/zip/$slug/composer.json" "$work/composer/"
    (cd "$work/composer" && "${composer_command[@]}" validate --strict --no-check-lock --no-interaction >/dev/null 2>&1) || fail 'composer validate rejects the shipped composer.json'
elif [[ "${BUCKMERCE_PLAID_REQUIRE_COMPOSER:-0}" == 1 ]]; then
    fail 'Composer is required to validate the shipped composer.json'
else
    printf 'Note: Composer is not installed; the shipped composer.json was checked structurally only.\n' >&2
fi
if grep -rIlE --include='*.php' '^namespace[[:space:]]+Firebase\\' "$work/zip" >/dev/null 2>&1; then
    fail 'the bundled library is shipped under its unprefixed namespace'
fi
jwt_sources=$(find "$work/zip/$slug/vendor-prefixed/firebase/php-jwt/src" -name '*.php' | wc -l)
jwt_prefixed=$(grep -rlE '^namespace Buckmerce\\Plaid\\Vendor\\Firebase\\JWT;' "$work/zip/$slug/vendor-prefixed/firebase/php-jwt/src" | wc -l)
[[ "$jwt_sources" -gt 0 && "$jwt_sources" -eq "$jwt_prefixed" ]] || fail 'every bundled firebase/php-jwt class must live in Buckmerce\Plaid\Vendor\Firebase\JWT'
# firebase/php-jwt is BSD-3-Clause (GPL-compatible): its license text ships with the code and the
# packaged Composer metadata declares it.
grep -q 'Redistribution and use in source and binary forms' "$work/zip/$slug/vendor-prefixed/firebase/php-jwt/LICENSE" || fail 'the bundled firebase/php-jwt LICENSE is not the BSD license text'
php -r '
    $installed = json_decode((string) file_get_contents($argv[1]), true);
    $packages = is_array($installed) ? ($installed["packages"] ?? array()) : array();
    $licenses = array();
    foreach ($packages as $package) {
        $licenses[(string) ($package["name"] ?? "")] = $package["license"] ?? array();
    }
    exit(array("firebase/php-jwt" => array("BSD-3-Clause")) === $licenses ? 0 : 1);
' "$work/zip/$slug/vendor-prefixed/composer/installed.json" || fail 'vendor-prefixed must bundle exactly firebase/php-jwt under BSD-3-Clause (a new or relicensed library needs a release decision)'

# 5. No source maps, local paths or credentials.
if grep -rIl --include='*.js' --include='*.css' 'sourceMappingURL' "$work/zip" >/dev/null 2>&1; then
    fail 'compiled assets reference source maps'
fi
if grep -rIlE '(/home/[a-z0-9_-]+/|/opt/lampp|/tmp/buckmerce-|/Users/[A-Za-z0-9_-]+/|[A-Z]:\\\\Users\\\\)' "$work/zip" >/dev/null 2>&1; then
    grep -rIlE '(/home/[a-z0-9_-]+/|/opt/lampp|/tmp/buckmerce-|/Users/[A-Za-z0-9_-]+/|[A-Z]:\\\\Users\\\\)' "$work/zip" | head -5 >&2
    fail 'local filesystem paths found in shipped files'
fi
credentials='(PLAID-SECRET|secret|SECRET|Secret)[A-Za-z_]*["'"'"']?[[:space:]]*[:=>]+[[:space:]]*["'"'"'][0-9a-f]{30}["'"'"']'
credentials+='|(access|link|public|processor)-(sandbox|production)-[0-9a-f]{8}-[0-9a-f]{4}-'
credentials+='|-----BEGIN ([A-Z]+ )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{40,}|AKIA[0-9A-Z]{16}'
credentials+='|NGROK_AUTHTOKEN[[:space:]]*=[[:space:]]*[0-9A-Za-z_]{20,}'
credentials+='|Authorization:[[:space:]]*(Basic|Bearer)[[:space:]]+[A-Za-z0-9+/=._-]{16,}'
credentials+='|[a-z][a-z0-9+.-]*://[^/[:space:]:@"'"'"']+:[^/[:space:]:@"'"'"']+@'
if grep -raIlE -- "$credentials" "$work/zip" >/dev/null 2>&1; then
    grep -raIlE -- "$credentials" "$work/zip" | sed "s|^$work/zip/||" | head -5 >&2
    fail 'something that looks like a credential is in the ZIP'
fi
# Values of the local .env (Sandbox credentials, tokens, passwords) never reach the package.
# Only variable names are printed.
if [[ -f "$base_dir/.env" ]]; then
    while IFS='=' read -r name value; do
        [[ "$name" =~ ^[A-Za-z0-9_]+$ ]] || continue
        [[ "$name" =~ (SECRET|PASSWORD|TOKEN|CLIEND_ID|CLIENT_ID|KEY|DOMAIN|USERNAME) ]] || continue
        value=$(sed -E 's/^[[:space:]"'"'"']+|[[:space:]"'"'"']+$//g' <<<"$value")
        [[ ${#value} -ge 8 ]] || continue
        case "$value" in localhost* | 127.0.0.1* | user_good | pass_good) continue ;; esac
        if grep -raqF -- "$value" "$work/zip"; then
            fail "the value of .env variable $name is in the ZIP"
        fi
    done < <(grep -E '^[A-Za-z0-9_]+=' "$base_dir/.env")
fi

# 6. Compiled assets equal a fresh build of the current sources.
if [[ "${BUCKMERCE_PLAID_SKIP_ASSET_PARITY:-0}" != 1 ]]; then
    mkdir -p "$work/build"
    cp -R "$base_dir/resources" "$base_dir/package.json" "$base_dir/tsconfig.json" "$work/build/"
    mkdir -p "$work/build/scripts" && cp "$base_dir/scripts/copy-images.sh" "$work/build/scripts/"
    ln -s "$base_dir/node_modules" "$work/build/node_modules"
    (cd "$work/build" && npx --no-install parcel build --no-content-hash --no-cache >/dev/null 2>&1 && bash scripts/copy-images.sh >/dev/null)
    while IFS= read -r asset; do
        cmp -s "$work/build/$asset" "$work/zip/$slug/$asset" || fail "stale compiled asset: $asset differs from a fresh build"
    done < <(cd "$work/build" && find assets -type f | LC_ALL=C sort)
    fresh_count=$(cd "$work/build" && find assets -type f | wc -l)
    zip_count=$(grep -c "^$slug/assets/" <<<"$listing" || true)
    [[ "$fresh_count" -eq "$zip_count" ]] || fail "the ZIP has $zip_count asset files, a fresh build has $fresh_count"
fi

# 7. Identity: no former identifier in the package or the repository (scripts/check-identity.sh),
#    and the slug the plugin used before its WordPress.org identity appears nowhere in the package:
#    not in a file name, not in code, readme, translations or compiled files (binaries are read
#    as text). Assembled from fragments so that this script does not contain it.
bash "$base_dir/scripts/check-identity.sh" "$zip" >/dev/null || fail 'former identifiers found (run: bash scripts/check-identity.sh <zip>)'
superseded_slug='buckmerce-''for-plaid'
if grep -q -- "$superseded_slug" <<<"$listing"; then
    fail "file names carry the superseded slug: $(grep -- "$superseded_slug" <<<"$listing" | head -n 5 | tr '\n' ' ')"
fi
superseded_hits=$(cd "$work/zip" && grep -rac -- "$superseded_slug" . | grep -v ':0$' || true)
if [[ -n "$superseded_hits" ]]; then
    printf '%s\n' "$superseded_hits" | sed 's|^\./||; s/^/    /' | head -n 10 >&2
    fail 'the superseded slug is in shipped files (file:occurrences above); the only slug is '"$slug"
fi

# 8. Versions inside the ZIP agree with its name; text domain and main file follow the slug.
main="$work/zip/$slug/$slug.php"
zip_header=$(grep -m1 '^ \* Version:' "$main" | sed -E 's/^ \* Version:[[:space:]]*//')
zip_constant=$(grep -m1 "define( 'BUCKMERCE_PLAID_VERSION'" "$main" | sed -E "s/.*'BUCKMERCE_PLAID_VERSION',[[:space:]]*'([^']+)'.*/\1/")
zip_stable=$(grep -m1 '^Stable tag:' "$work/zip/$slug/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//')
[[ "$zip_header" == "$version" && "$zip_constant" == "$version" && "$zip_stable" == "$version" ]] || fail "versions in the ZIP disagree: header=$zip_header constant=$zip_constant readme=$zip_stable name=$version"
grep -q "^ \* Text Domain: $slug\$" "$main" || fail "the plugin header must declare Text Domain: $slug"
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "PHP syntax error in ${file#"$work/zip/"}"
done < <(find "$work/zip" -name '*.php' -print0)

sha=$(sha256sum "$zip" | cut -d' ' -f1)
printf '%s  %s\n' "$sha" "$(basename "$zip")" > "$zip.sha256"
printf 'Package verified: %s (%d files)\nSHA-256: %s\n' "$(basename "$zip")" "$(wc -l <<<"$listing")" "$sha"
