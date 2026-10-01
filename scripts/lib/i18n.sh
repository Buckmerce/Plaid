# shellcheck shell=bash
# Translation helpers (sourced, never executed). WP-CLI's i18n commands need no WordPress site.

bmfp_i18n_domain=buckmerce-for-plaid

# bmfp_make_pot <output file>: extracts every translatable string of the shipped PHP code.
bmfp_make_pot() {
    local output=$1 base=${bmfp_base_dir:?}
    (cd "$base" && wp i18n make-pot . "$output" --slug="$bmfp_i18n_domain" --domain="$bmfp_i18n_domain" \
        --exclude=vendor,vendor-prefixed,node_modules,tests,dist,assets,output,resources,docs,scripts --quiet)
}
