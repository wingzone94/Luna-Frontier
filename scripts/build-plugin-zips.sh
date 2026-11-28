#!/usr/bin/env bash
# Package all standalone plugin ZIPs in production_plugins/ from plugins-embedded/.
# Usage:
#   bash scripts/build-plugin-zips.sh            # rebuild all 11 standalone plugin ZIPs
#   bash scripts/build-plugin-zips.sh slug1 ...  # rebuild only specified slugs
set -euo pipefail

repo_dir=$(cd "$(dirname "$0")/.." && pwd)
out_dir="$repo_dir/production_plugins"
mkdir -p "$out_dir"

ALL_SLUGS=(
	luminous-blocks
	luminous-interactivity
	luminous-nexus
	luna-interactive
	node-ai-tools
	node-connect
	node-flow
	node-image-compressor
	node-library
	node-seo-tools
	node-signal
)

if [ "$#" -gt 0 ]; then
	slugs=("$@")
else
	slugs=("${ALL_SLUGS[@]}")
fi

for slug in "${slugs[@]}"; do
	src_dir="$repo_dir/plugins-embedded/$slug"
	if [ ! -d "$src_dir" ]; then
		echo "Source directory not found: $src_dir" >&2
		exit 1
	fi

	staging=$(mktemp -d)
	mkdir -p "$staging/$slug"
	rsync -a --exclude='.DS_Store' "$src_dir/" "$staging/$slug/"
	(cd "$staging" && zip -qr "$staging/${slug}.zip" "$slug")
	mv "$staging/${slug}.zip" "$out_dir/${slug}.zip"
	rm -rf "$staging"
done
