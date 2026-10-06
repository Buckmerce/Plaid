#!/usr/bin/env bash
# Install the Chromium build of the pinned Playwright CLI (scripts/lib/test-env.sh), the one the
# browser and Sandbox suites then drive.
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/test-env.sh
. "$base_dir/scripts/lib/test-env.sh"

npx --yes --package "$bmfp_playwright_cli_package" playwright-cli install-browser chromium
