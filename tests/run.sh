#!/usr/bin/env bash
# Run every test-*.php in this directory, plus a PHP lint sweep of the plugin.
set -uo pipefail
cd "$(dirname "$0")"

PLUGIN_DIR="../newsdesk-ai"
fail=0

echo "▸ Lint sweep"
lint_errors=$(find "$PLUGIN_DIR" -name '*.php' -print0 \
  | xargs -0 -P8 -n1 php -l 2>&1 \
  | grep -v '^No syntax errors detected' || true)
if [ -n "$lint_errors" ]; then
  echo "$lint_errors"
  fail=1
else
  count=$(find "$PLUGIN_DIR" -name '*.php' | wc -l)
  echo "  ✓ $count files, 0 syntax errors"
fi

echo
echo "▸ PHP 7.4 compatibility guard"
# The runtime here is PHP 8.4 but the plugin targets 7.4, and `php -l` on 8.4
# will happily accept 8.x-only syntax. Grep for it explicitly.
bad=$(grep -rnE '^\s*(readonly|enum) |\bmatch\s*\(|\?->|#\[' \
  --include='*.php' "$PLUGIN_DIR/src" "$PLUGIN_DIR"/*.php 2>/dev/null \
  | grep -v '^\s*\*' || true)
if [ -n "$bad" ]; then
  echo "  ✗ PHP 8.0+ syntax found:"
  echo "$bad"
  fail=1
else
  echo "  ✓ no PHP 8.0+ syntax"
fi

echo
for t in test-*.php; do
  [ -e "$t" ] || continue
  echo "▸ $t"
  if ! php "$t"; then
    fail=1
  fi
  echo
done

if [ "$fail" -ne 0 ]; then
  echo "════════ SUITE FAILED ════════"
  exit 1
fi
echo "════════ SUITE PASSED ════════"
