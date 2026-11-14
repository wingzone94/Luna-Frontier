#!/usr/bin/env bash
#
# Luna Frontier 配布 ZIP（luna.zip）生成スクリプト。
#
# HOW_TO_RELEASE.md 節5 の正本。ローカルのリリース作業と
# .github/workflows/release-luna-frontier.yml の両方から呼ばれる。
# rsync 除外は HOW_TO_RELEASE.md 節5 と整合させること。
#
# 前提:
# - 実行前に bun run build が済んでいること（dist/ が最新）。
# - build.json が更新済みであること（HOW_TO_RELEASE.md 節4）。
# - 生成先 <repo-root>/luna.zip。その後 .gitignore により追跡対象外。

set -euo pipefail

repo_dir=$(cd "$(dirname "$0")/.." && pwd)
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

mkdir -p "$tmpdir/luna-frontier"

rsync -a \
  --exclude='.git' \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.agents/' \
  --exclude='.claude/' \
  --exclude='.codex/' \
  --exclude='.cursor/' \
  --exclude='.gemini/' \
  --exclude='.opencode/' \
  --exclude='.DS_Store' \
  --exclude='.!*!.DS_Store' \
  --exclude='.tmp*/' \
  --exclude='scratch/' \
  --exclude='scripts/' \
  --exclude='node_modules/' \
  --exclude='*.zip' \
  --exclude='production_plugins/' \
  --exclude='/luna-interactive/' \
  --exclude='luna-frontier/' \
  --exclude='src/styles/' \
  --exclude='src/scripts/' \
  --exclude='src/fonts/' \
  --exclude='src/*.js' \
  --exclude='/vendor/' \
  --exclude='tests/' \
  --exclude='test-results/' \
  --exclude='design/' \
  --exclude='memory/' \
  --exclude='AGENTS.md' \
  --exclude='AGENT.MD' \
  --exclude='AI-AGENTS.md' \
  --exclude='AI.md' \
  --exclude='CHATGPT.md' \
  --exclude='CLAUDE.md' \
  --exclude='DESIGN.MD' \
  --exclude='GEMINI.md' \
  --exclude='STATUS.md' \
  --exclude='NODE-2.0.md' \
  --exclude='NODE-1.3.md' \
  --exclude='STRUCTURAL-REVIEW-1.2.md' \
  --exclude='REFACTORING_PLAN.md' \
  --exclude='NODE_LIBRARY_REGRESSION_PLAN.md' \
  --exclude='1.2*.md' \
  --exclude='AUDIT-2026-11.md' \
  --exclude='SPEC-2026-11-FIX.md' \
  --exclude='TECHNOLOGIES.md' \
  --exclude='skills-lock.json' \
  --exclude='gemini_targets.txt' \
  --exclude='lum-tracking-guard.php' \
  --exclude='package.json' \
  --exclude='bun.lock' \
  --exclude='vite.config.js' \
  --exclude='HOW_TO_RELEASE.md' \
  --exclude='CHANGELOG.md' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='phpunit.xml.dist' \
  --exclude='.phpunit.result.cache' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='assets/css/main.css' \
  --exclude='assets/css/material3.css' \
  --exclude='*.ttf' \
  --exclude='*.otf' \
  --exclude='*.woff' \
  --exclude='*.woff2' \
  "$repo_dir/" "$tmpdir/luna-frontier/"

cd "$tmpdir"
zip -qr "$repo_dir/luna.zip" luna-frontier
