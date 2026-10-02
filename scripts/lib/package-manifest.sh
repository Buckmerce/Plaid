# shellcheck shell=bash
# Release manifest (sourced, never executed): the ONLY paths the WordPress.org distribution may
# contain. scripts/package.sh stages exactly these files into an empty directory and
# scripts/verify-package.sh rejects a ZIP that holds anything else, so the package is defined by
# an allowlist and never by "everything except …". Paths are relative to the plugin directory
# inside the ZIP (buckmerce-plaid/).
#
# A new kind of runtime file is added here on purpose, with its reason; the development project
# (tests, docs, scripts, front-end sources, tooling configuration) is never listed.

# Everything directly inside buckmerce-plaid/. Nothing else may sit beside these.
# shellcheck disable=SC2034
bmfp_release_top_level=(
    buckmerce-plaid.php   # main plugin file: header, constants, autoloaders, activation hooks
    readme.txt            # WordPress.org readme
    LICENSE               # GPL-2.0-or-later
    composer.json         # dependency definition of the bundled, prefixed library (reviewability)
    uninstall.php         # conservative uninstall
    src                   # runtime PHP classes (Buckmerce\Plaid\)
    assets                # compiled CSS/JS and images
    vendor-prefixed       # firebase/php-jwt under Buckmerce\Plaid\Vendor\ (webhook verification)
)

# Runtime directories package.sh reads; every file in them is either allowlisted below or named
# in bmfp_release_not_shipped, otherwise the build stops.
# shellcheck disable=SC2034
bmfp_release_source_dirs=(src assets vendor-prefixed)

# One extended regular expression per kind of runtime file.
bmfp_release_allowlist=(
    '^buckmerce-plaid\.php$'
    '^readme\.txt$'
    '^LICENSE$'
    '^composer\.json$'
    '^uninstall\.php$'
    '^src/([A-Za-z0-9]+/)*[A-Za-z0-9]+\.php$'
    '^assets/(admin-settings|payment-page)\.css$'
    '^assets/build/(admin-settings|blocks|payment-page)\.js$'
    '^assets/images/[a-z0-9]+(-[a-z0-9]+)*\.(svg|png|jpg|webp)$'
    '^vendor-prefixed/autoload\.php$'
    '^vendor-prefixed/composer/(ClassLoader|InstalledVersions|autoload_[a-z0-9]+|installed|platform_check)\.php$'
    '^vendor-prefixed/composer/(installed\.json|LICENSE)$'
    '^vendor-prefixed/firebase/php-jwt/LICENSE$'
    '^vendor-prefixed/firebase/php-jwt/src/[A-Za-z]+\.php$'
)

# Files that live in a runtime directory of the repository but are not runtime files: directory
# placeholders and the bundled library's own documentation and package metadata.
bmfp_release_not_shipped=(
    '(^|/)\.gitkeep$'
    '^vendor-prefixed/firebase/php-jwt/(README\.md|CHANGELOG\.md|composer\.json|VERSION)$'
)

# Files without which the plugin cannot run or cannot be reviewed.
# shellcheck disable=SC2034
bmfp_release_required=(
    buckmerce-plaid.php
    readme.txt
    LICENSE
    composer.json
    uninstall.php
    src/Plugin.php
    src/Bootstrap.php
    assets/admin-settings.css
    assets/payment-page.css
    assets/build/admin-settings.js
    assets/build/blocks.js
    assets/build/payment-page.js
    assets/images/buckmerce-mark.svg
    vendor-prefixed/autoload.php
    vendor-prefixed/composer/autoload_real.php
    vendor-prefixed/composer/installed.php
    vendor-prefixed/composer/LICENSE
    vendor-prefixed/firebase/php-jwt/LICENSE
    vendor-prefixed/firebase/php-jwt/src/JWT.php
    vendor-prefixed/firebase/php-jwt/src/JWK.php
    vendor-prefixed/firebase/php-jwt/src/Key.php
)

# bmfp_release_matches <path> <pattern>...: true when the path matches one of the patterns.
bmfp_release_matches() {
    local path=$1 pattern
    shift
    for pattern in "$@"; do
        [[ "$path" =~ $pattern ]] && return 0
    done
    return 1
}

# bmfp_release_is_allowed <path relative to the plugin directory>
bmfp_release_is_allowed() {
    bmfp_release_matches "$1" "${bmfp_release_allowlist[@]}"
}

# bmfp_release_is_not_shipped <path relative to the repository root>
bmfp_release_is_not_shipped() {
    bmfp_release_matches "$1" "${bmfp_release_not_shipped[@]}"
}
