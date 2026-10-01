#!/usr/bin/env bash
# Refreshes the LOCAL, git-ignored copy of the official Plaid pages PayBridge depends on
# (docs/api/plaid-mirror/). Plaid publishes every docs page as Markdown at
# https://plaid.com/docs/<path>/index.html.md. The copy is a reading aid only: the authority is
# always the live page, and docs/api/PLAID_TRANSFER.md records what PayBridge verified and when.
# Plaid's documentation is © Plaid Inc.; it is therefore not redistributed in this repository.
# Usage: bash scripts/fetch-plaid-docs.sh
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
target="$base_dir/docs/api/plaid-mirror"
pages=(
    transfer
    transfer/using-transfer-ui
    transfer/creating-transfers
    transfer/reconciling-transfers
    transfer/refunds
    transfer/troubleshooting
    transfer/sandbox
    transfer/glossary
    api/products/transfer/account-linking
    api/products/transfer/initiating-transfers
    api/products/transfer/reading-transfers
    api/products/transfer/refunds
    api/webhooks/webhook-verification
    api/sandbox
    api/link
    link/customization
    link/web
    errors/transfer
    changelog
)
today=$(date -u +%F)
mkdir -p "$target"
for page in "${pages[@]}"; do
    url="https://plaid.com/docs/$page/"
    out="$target/$page.md"
    [[ "$page" == transfer ]] && out="$target/transfer/README.md"
    mkdir -p "$(dirname "$out")"
    tmp=$(mktemp)
    if curl -fsSL --max-time 60 -o "$tmp" "${url}index.html.md"; then
        { printf -- '---\nsource: "%s"\nretrieved: "%s"\n---\n\n' "$url" "$today"; cat "$tmp"; } >"$out"
        printf 'fetched %s\n' "$url"
    else
        printf 'FAILED  %s\n' "$url" >&2
    fi
    rm -f "$tmp"
done
printf 'Plaid documentation copy refreshed in %s (retrieved %s). Re-verify docs/api/PLAID_TRANSFER.md against it.\n' "${target#"$base_dir"/}" "$today"
