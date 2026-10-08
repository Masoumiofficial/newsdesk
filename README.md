# NewsDesk AI — development repository

> **This repository is not the plugin.** The plugin lives in
> [`newsdesk-ai/`](newsdesk-ai/). Everything beside it is tooling that must not
> ship to a customer. Install the built zip from
> [Releases](../../releases), not a clone of this repo.

An AI newsroom for WordPress that refuses to publish what it cannot verify.
Built by [EtehadWP](https://etehadwp.com/) — اتحاد وردپرس.

---

## Layout

| Path | Ships to customers? | What it is |
|---|---|---|
| `newsdesk-ai/` | **yes** | The plugin. This directory is what gets zipped. |
| `tests/` | no | 23 test files, 802 assertions. No DB, no WordPress needed. |
| `tools/` | no | `release.sh` (build + verify), `render-previews.php` (screenshots). |
| `.wordpress-org/` | no | Banner and icon for the wordpress.org listing. |
| `marketing/` | no | CodeCanyon thumbnail and preview images. |
| `.i18n/` | no | Provenance maps for rebuilding the Persian catalogue. |
| `dist/`, `previews/` | no | Generated. Git-ignored. |

---

## Working on it

```bash
# Run everything. Needs only php-cli with mbstring + simplexml.
bash tests/run.sh

# Build a release and verify it from the EXTRACTED archive.
bash tools/release.sh
# -> dist/newsdesk-ai-<version>.zip

# Render the 12 admin screens to standalone HTML, for screenshots.
php tools/render-previews.php
# -> previews/*.html  (open in a browser, screenshot, save to .wordpress-org/)
```

There is no build step, no Composer install, and no npm. The plugin is plain
PHP 7.4 with its own PSR-4 autoloader.

### Why `release.sh` exists

Version 2.0.0 once shipped with `Version: 2.0.0` in the plugin header and
`NEWSDESK_VERSION = '1.0.0'` in the code. WordPress reads the header, so the
plugin reported the wrong version to the update system. The divergence was
invisible in the working tree and only appeared after unzipping the artifact.

Everything `release.sh` checks, it checks against the **extracted package**:
version agreement across four files, required files present, no `.git`/`.po`
leakage, no old-brand strings, vendor attribution intact, every PHP file lints,
and the drafts-only invariant still holds in code.

---

## The rules this codebase enforces

These are design constraints, not preferences. Changing one is a product
decision, and each is pinned by tests.

1. **Drafts only.** Every write forces `post_status = draft`. The entire
   WordPress write surface is one file, `WordPressPostWriter.php`. There is no
   auto-publish setting to turn on.
2. **It is allowed to produce nothing.** Gates run before any AI spend. If the
   24-hour window yields nothing it widens to 7 days; if still nothing, the
   outcome is `NO NEWS`.
3. **Nothing fabricated is presented as real.** No mock provider pretends to be
   an AI. Security facts (CVE, CVSS, affected versions) are extracted by
   deterministic pattern matching, never by a model.
4. **A critical audit failure blocks the draft.** Six of the fifteen audit axes
   are blocking; failing one means no draft is created at all.
5. **Uninstall keeps data by default.** Deletion is opt-in and must be enabled
   before the plugin is removed.

---

## Conventions

| Thing | Value |
|---|---|
| Text domain / slug | `newsdesk-ai` |
| Namespace | `NewsDesk\AI` |
| Database tables | `{wp_prefix}newsdesk_*` (17) |
| Post meta | `_newsdesk_*` |
| REST namespace | `nd-newsroom/v1` |
| CSS / page slugs | `nd-*` |
| PHP floor | 7.4 (syntax enforced by a grep sweep in `run.sh`) |
| WordPress floor | 6.5 |

`src/Support/Branding.php` is the single source for the product name, vendor
name and every external URL. A test fails if any other file in `src/`
hardcodes the vendor domain.

---

## Testing notes

The harness promotes PHP notices to failures, so referencing a property that
does not exist on an entity is a hard error rather than a blank cell.

Two signatures that are easy to get backwards:

```php
T::contains( $needle, $haystack, $label );   // needle FIRST
T::eq( $expected, $actual, $label );          // not T::same()
```

`tests/bootstrap.php` must load in this order: autoloader → `stubs/FakeWpDb.php`
→ `stubs/fixtures.php`. Never stub `global $wpdb` as a bare `stdClass`; use
`FakeGlobalWpdb`.

### The `formats()` rule

`wpdb` maps the `$format` array to `$data` **by position**. A missing specifier
shifts every later one and silently miscasts the data — no error, wrong values.

**A column added to `Story::toDbRow()` must get a matching specifier at the same
index in `formats()`.** `tests/test-u3-format-alignment.php` enforces this
generically; it caught a 44-vs-43 mismatch the day it was written.

---

## Releasing

1. Bump the version in **both** places in `newsdesk-ai/newsdesk-ai.php` — the
   `Version:` header and the `NEWSDESK_VERSION` constant are separate strings.
2. Update `Stable tag:` and the changelog in `newsdesk-ai/readme.txt`, and
   `newsdesk-ai/CHANGELOG.md`.
3. `bash tools/release.sh` — it refuses to build if any of those disagree.
4. Tag and push; `.github/workflows/release.yml` builds the tagged commit,
   verifies the extracted archive, and attaches the zip to the release.

---

## Licence

GPL-2.0-or-later. Copyright © EtehadWP (اتحاد وردپرس), <https://etehadwp.com/>.
