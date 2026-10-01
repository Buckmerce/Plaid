#!/usr/bin/env bash
# Regenerates the translation template and every bundled translation with WP-CLI (wp i18n):
#   1. languages/buckmerce-plaid.pot from the PHP sources (left untouched when only its
#      creation date would change);
#   2. merges added and removed strings into every languages/buckmerce-plaid-<locale>.po
#      (wp i18n update-po) — new strings arrive untranslated;
#   3. compiles .mo and .l10n.php files (wp i18n make-mo / make-php) and script translation
#      JSON files when a .po contains JavaScript strings (wp i18n make-json).
# After translating new strings run this script again; scripts/check-translations.sh must pass.
# A new locale starts as a copy of the .pot named buckmerce-plaid-<locale>.po with the
# "Language" and "Plural-Forms" headers of that locale.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/i18n.sh
. "$base_dir/scripts/lib/i18n.sh"
command -v wp >/dev/null 2>&1 || { printf 'WP-CLI (wp) is required to build translations.\n' >&2; exit 1; }

languages="$base_dir/languages"
pot="$languages/$bmfp_i18n_domain.pot"
fresh=$(mktemp)
trap 'rm -f "$fresh"' EXIT
bmfp_make_pot "$fresh"
if [[ -f "$pot" ]] && diff -q <(grep -v '^"POT-Creation-Date: ' "$pot") <(grep -v '^"POT-Creation-Date: ' "$fresh") >/dev/null; then
    printf 'Template is current: %s\n' "${pot#"$base_dir"/}"
else
    cp "$fresh" "$pot"
    printf 'Template updated: %s\n' "${pot#"$base_dir"/}"
fi

if compgen -G "$languages/$bmfp_i18n_domain-*.po" >/dev/null; then
    wp i18n update-po "$pot" "$languages"
    wp i18n make-mo "$languages"
    wp i18n make-php "$languages"
    wp i18n make-json "$languages" --no-purge
else
    printf 'No bundled translations (languages/%s-<locale>.po) to build.\n' "$bmfp_i18n_domain"
fi
bash "$base_dir/scripts/check-translations.sh"
