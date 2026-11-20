#!/usr/bin/env bash
# Package the canonical Library source for standalone installs. Run after validation.
set -euo pipefail
repo_dir=$(cd "$(dirname "$0")/.." && pwd)
output=${1:-"$repo_dir/production_plugins/node-library.zip"}
mkdir -p "$(dirname "$output")"
output=$(cd "$(dirname "$output")" && pwd)/$(basename "$output")
staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT
mkdir "$staging/node-library"
rsync -a --exclude='.DS_Store' "$repo_dir/plugins-embedded/node-library/" "$staging/node-library/"
(cd "$staging" && zip -qr "$staging/library.zip" node-library)
mv "$staging/library.zip" "$output"
