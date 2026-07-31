#!/usr/bin/env bash
# ORBI-54: build a clean, installable plugin zip.
#
# WHY THIS EXISTS: as of ORBI-54 this repo also contains Node tooling (package.json,
# node_modules, tests/, .wp-env.json) for the e2e suite. None of that belongs in
# WordPress. Zipping the working directory — or downloading the repo as a zip from
# GitHub — now sweeps up test files and can pull in a ~200MB node_modules. Use this
# instead: it copies only what the plugin actually needs.
#
#   npm run zip   →   build/soames-wordpress-plugin.zip
#
# Reminder: deploy the plugin WHOLE. Editor JS in assets/ and the PHP in includes/
# must ship together — a stale blocks.php silently breaks block rendering even when
# the editor still looks correct.
set -euo pipefail

SLUG="soames-wordpress-plugin"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/build"
STAGE="$OUT/$SLUG"

rm -rf "$STAGE" "$OUT/$SLUG.zip"
mkdir -p "$STAGE"

# The allowlist IS the contract — add new shippable paths here explicitly. An
# allowlist fails safe: a new dev-only directory is excluded by default rather than
# silently shipped.
cp "$ROOT/$SLUG.php" "$STAGE/"
cp -R "$ROOT/includes" "$STAGE/"
cp -R "$ROOT/assets" "$STAGE/"

( cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG" )
rm -rf "$STAGE"

echo "Built $OUT/$SLUG.zip"
unzip -l "$OUT/$SLUG.zip" | tail -n 3

# Guard: if any of the dev-only paths ever leak in, fail loudly rather than letting
# someone upload a 200MB zip full of tests.
if unzip -l "$OUT/$SLUG.zip" | grep -qE "node_modules|/tests/|package\.json|\.wp-env"; then
  echo "ERROR: dev-only files leaked into the zip" >&2
  exit 1
fi
