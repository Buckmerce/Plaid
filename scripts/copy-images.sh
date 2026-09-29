#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"

SRC_DIR="$BASE_DIR/resources/images"
DEST_DIR="$BASE_DIR/assets/images"

rm -rf "$DEST_DIR"

shopt -s nullglob
FILES=("$SRC_DIR"/*)
shopt -u nullglob

if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "No images in resources/images, skipping copy."
    exit 0
fi

mkdir -p "$DEST_DIR"

for FILE in "${FILES[@]}"; do
    BASENAME="$(basename "$FILE")"
    [[ "$BASENAME" == ".gitkeep" ]] && continue
    cp -a "$FILE" "$DEST_DIR/"
done

echo "Copied images from resources/images to assets/images."
