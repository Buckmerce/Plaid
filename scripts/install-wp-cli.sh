#!/usr/bin/env bash
# Install the pinned WP-CLI release into a caller-owned tools directory.
set -euo pipefail

target=${1:?Usage: bash scripts/install-wp-cli.sh <tools-directory>}
version=2.12.0
sha256=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

curl --fail --silent --show-error --location --retry 3 --retry-all-errors \
    --connect-timeout 15 --max-time 120 \
    "https://github.com/wp-cli/wp-cli/releases/download/v$version/wp-cli-$version.phar" \
    -o "$work/wp"
printf '%s  %s\n' "$sha256" "$work/wp" | sha256sum --check --status
php "$work/wp" --version | grep -Fx "WP-CLI $version"
mkdir -p "$target"
install -m 0755 "$work/wp" "$target/wp"
