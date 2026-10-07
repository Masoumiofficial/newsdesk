#!/usr/bin/env bash
#
# Build and verify a release artifact.
#
# This exists because of a real bug: the plugin header said 2.0.0 while the
# NEWSDESK_VERSION constant said 1.0.0. WordPress reads the header, so the
# plugin reported the wrong version to the updater. It was invisible in the
# working tree and only surfaced after unzipping the archive. Everything below
# is therefore checked against the EXTRACTED package, never the source tree.
#
# Usage: bash tools/release.sh [version]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/newsdesk-ai"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

fail=0
note()  { printf '  %s\n' "$*"; }
ok()    { printf '  \033[32mok\033[0m    %s\n' "$*"; }
bad()   { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; fail=1; }

echo "==> Reading version from the source of truth (the plugin header)"
HEADER_V="$(grep -m1 '^ \* Version:' "$SRC/newsdesk-ai.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')"
WANT="${1:-$HEADER_V}"
note "header version: $HEADER_V"
note "building:       $WANT"

ZIP="$ROOT/dist/newsdesk-ai-$WANT.zip"
mkdir -p "$ROOT/dist"

echo
echo "==> Packaging"
rm -f "$ZIP"
( cd "$ROOT" && zip -rq "$ZIP" newsdesk-ai \
    -x '*/.git/*' '*/.DS_Store' '*.po' '*/node_modules/*' '*/previews/*' )
note "$(du -h "$ZIP" | cut -f1)  $ZIP"

echo
echo "==> Extracting to a clean directory"
unzip -qo "$ZIP" -d "$STAGE"
P="$STAGE/newsdesk-ai"
note "$(find "$P" -type f | wc -l) files extracted"

echo
echo "==> Version consistency (the bug that shipped once already)"
H="$(grep -m1 '^ \* Version:' "$P/newsdesk-ai.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')"
C="$(grep -m1 "define( *'NEWSDESK_VERSION'" "$P/newsdesk-ai.php" | sed "s/.*'NEWSDESK_VERSION', *'//;s/'.*//")"
S="$(grep -m1 '^Stable tag:' "$P/readme.txt" | sed 's/.*:[[:space:]]*//' | tr -d '\r')"
[ "$H" = "$WANT" ] && ok "plugin header       $H" || bad "plugin header       $H (expected $WANT)"
[ "$C" = "$WANT" ] && ok "NEWSDESK_VERSION    $C" || bad "NEWSDESK_VERSION    $C (expected $WANT)"
[ "$S" = "$WANT" ] && ok "readme stable tag   $S" || bad "readme stable tag   $S (expected $WANT)"
grep -q "^= $WANT " "$P/readme.txt"  && ok "readme changelog has $WANT"  || bad "readme changelog is missing $WANT"
grep -q "$WANT"     "$P/CHANGELOG.md" && ok "CHANGELOG.md has $WANT"     || bad "CHANGELOG.md is missing $WANT"

echo
echo "==> Required files"
for f in newsdesk-ai.php readme.txt LICENSE CHANGELOG.md README.md INSTALL.md \
         uninstall.php composer.json documentation/index.html \
         languages/newsdesk-ai.pot languages/newsdesk-ai-fa_IR.mo; do
  [ -f "$P/$f" ] && ok "$f" || bad "$f is MISSING"
done

echo
echo "==> Hygiene"
for pat in '.git' '.DS_Store' 'node_modules' '.idea' 'previews'; do
  n="$(find "$P" -name "$pat" | wc -l)"
  [ "$n" -eq 0 ] && ok "no $pat" || bad "$n x $pat leaked into the package"
done
n="$(find "$P" -name '*.po' | wc -l)"
[ "$n" -eq 0 ] && ok "no .po sources (only compiled .mo ships)" || bad "$n .po files leaked in"

echo
echo "==> Old-brand leaks"
for tok in 'etehadwp-ai-newsroom' 'EtehadWP\\AI\\Newsroom' 'ewp_'; do
  n="$( { grep -rl --binary-files=without-match -F "$tok" "$P" 2>/dev/null || true; } | wc -l )"
  [ "$n" -eq 0 ] && ok "no '$tok'" || { bad "'$tok' in $n file(s):"; { grep -rl -F "$tok" "$P" || true; } | sed "s|$P/|        |"; }
done

echo
echo "==> Vendor attribution"
grep -q 'Author: *EtehadWP'        "$P/newsdesk-ai.php" && ok "Author: EtehadWP"   || bad "Author header is wrong"
grep -q 'etehadwp.com'             "$P/newsdesk-ai.php" && ok "Author URI"         || bad "Author URI is missing"
grep -q 'etehadwp'                 "$P/readme.txt"      && ok "readme contributor" || bad "readme contributor is missing"
grep -q 'EtehadWP'                 "$P/LICENSE"         && ok "LICENSE copyright"  || bad "LICENSE copyright is missing"

echo
echo "==> Syntax (every PHP file in the package)"
if command -v php >/dev/null 2>&1; then
  errs="$(find "$P" -name '*.php' -exec php -l {} \; 2>&1 | grep -v '^No syntax errors' || true)"
  [ -z "$errs" ] && ok "$(find "$P" -name '*.php' | wc -l) files lint clean" || { bad "syntax errors:"; echo "$errs"; }
else
  note "php not installed; skipping lint"
fi

echo
echo "==> Safety invariants"
# The writer ASSIGNS the status (overriding any caller value); it is not an
# array literal, so match the assignment and prove no publish path exists.
if grep -rqE "\\\$post\\['post_status'\\][[:space:]]*=[[:space:]]*'draft'" "$P/src"; then
  ok "post_status is force-assigned to 'draft'"
else
  bad "drafts-only enforcement not found in the writer"
fi
# Only WRITE sites matter. A 'publish' in a get_posts() query is a read of the
# existing archive (cannibalization analysis), not a publish action -- so scope
# the check to files that actually call wp_insert_post / wp_update_post.
writers="$( { grep -rlE "wp_(insert|update)_post\\(" "$P/src" || true; } )"
pub=0
for w in $writers; do
  n="$( { grep -nE "'post_status'[[:space:]]*(=>|=)[[:space:]]*'publish'" "$w" || true; } | wc -l )"
  if [ "$n" -gt 0 ]; then bad "publish write path in ${w#$P/}"; pub=$((pub+n)); fi
done
[ "$pub" -eq 0 ] && ok "no write path sets post_status to 'publish' ($(echo "$writers" | wc -w) writer file(s) checked)"
autop="$( { grep -rniE "auto.?publish[[:space:]]*'?[[:space:]]*(=>|=)[[:space:]]*true" "$P/src" || true; } | wc -l )"
[ "$autop" -eq 0 ] && ok "auto-publish is never defaulted on" || bad "auto-publish defaults to true somewhere"
n="$( { grep -rl "ABSPATH" "$P" --include='*.php' || true; } | wc -l )"
t="$(find "$P" -name '*.php' | wc -l)"
note "ABSPATH guards: $n / $t (uninstall.php correctly uses WP_UNINSTALL_PLUGIN instead)"

echo
echo "==> Screenshots declared vs shipped"
declared="$( sed -n '/^== Screenshots ==/,/^== Changelog ==/p' "$P/readme.txt" | { grep -c '^[0-9]\.' || true; } )"
shipped="$( { ls "$P/assets"/screenshot-*.png 2>/dev/null || true; } | wc -l )"
note "readme.txt declares $declared; package ships $shipped"
if [ "$declared" -gt 0 ] && [ "$shipped" -lt "$declared" ]; then
  note "wordpress.org serves screenshots from the SVN assets/ folder, not the zip,"
  note "so this does not block a wp.org release — but CodeCanyon needs real images."
  note "Render them with: php tools/render-previews.php"
fi

echo
if [ "$fail" -eq 0 ]; then
  printf '\033[32m==> RELEASE OK\033[0m  %s\n' "$ZIP"
else
  printf '\033[31m==> RELEASE BLOCKED\033[0m — fix the FAILs above\n'
  exit 1
fi
