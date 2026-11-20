#!/usr/bin/env bash
# Package the canonical AI Tools source after validation.
set -euo pipefail
repo_dir=$(cd "$(dirname "$0")/.." && pwd)
output=${1:-"$repo_dir/production_plugins/node-ai-tools.zip"}
mkdir -p "$(dirname "$output")"
output=$(cd "$(dirname "$output")" && pwd)/$(basename "$output")
staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT
mkdir "$staging/node-ai-tools"
rsync -a --exclude='.DS_Store' "$repo_dir/plugins-embedded/node-ai-tools/" "$staging/node-ai-tools/"
(cd "$staging" && zip -qr "$staging/plugin.zip" node-ai-tools)
mv "$staging/plugin.zip" "$output"
