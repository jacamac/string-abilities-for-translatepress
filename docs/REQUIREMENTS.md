# String Abilities for TranslatePress — Requirements

WordPress plugin that registers a small set of **WordPress Abilities** for reading, reviewing and correcting TranslatePress string translations. It ships **no MCP server and no REST routes**: abilities are exposed by whatever already exposes the site's abilities (core `/wp-json/wp-abilities/v1/`, a hosting provider's MCP connector, the MCP Adapter plugin).

> Audience: Claude Code. Owner: GitHub `jacamac`. GPL-2.0-or-later, to be published.
> Spec revision 7 (2026-10-23) — updates through Plugin Update Checker as in the owner's other plugin; case-insensitive search; `…` normalisation.
> Legend: **[VERIFY]** believed true, confirm in TranslatePress / WordPress source before relying on it.

---

## 1. Guiding principles (apply everywhere)

1. **Minimum code.** Fewest classes and files, no frameworks, no abstraction layers "for later". If a feature is not in §5, it does not exist; ideas go to `docs/LATER.md`.
2. **Quality that survives public review.** WordPress Coding Standards, `declare(strict_types=1)`, prepared statements, escaped output, clear names, short functions.
3. **Lean testing.** Automated tests only where a bug would be dangerous or silent (§9). Everything else: a short manual smoke test on the dev site. No coverage targets, no mocking of WordPress.
4. **Stateless.** No queues, no proposal tables, no background jobs. One table: the change log.
5. **Free TranslatePress only.** Depend on `translatepress-multilingual`; never on paid add-ons.
6. **Site-agnostic and leak-free.** No data from any real site and no credentials in code, tests, fixtures, docs, screenshots or commit messages. Enforced by CI (§9).

---

## 2. Identity

| Item | Value |
|---|---|
| Name | String Abilities for TranslatePress |
| Slug / main file | `string-abilities-for-translatepress` / `string-abilities-for-translatepress.php` |
| Repo | `jacamac/string-abilities-for-translatepress` |
| Ability namespace / category | `string-abilities/` / `string-abilities` |
| PHP namespace | `StringAbilities\` (PSR-4, `src/`) |
| Prefix (constants, options, table, caps, error codes) | `SAT_` / `sat_` |
| Text domain | `string-abilities-for-translatepress` |
| Requires | WordPress 6.9, PHP 8.1, `Requires Plugins: translatepress-multilingual` |

README states the plugin is **not affiliated with or endorsed by TranslatePress / Cozmoslabs**.

---

## 3. Context

- **Reference site** (private, do not mention in the repo): WordPress 7.1, PHP 8.4, MySQL 8.4, TranslatePress 3.3.8 (+ a paid add-on), Redis object cache, 5 languages.
- TranslatePress 3.3.8 already registers abilities in the `translatepress/` namespace for languages, license and enabling automatic translation. **None cover strings, and none read the automatic-translation state.** Do not duplicate them.
- **Typical workflow the plugin serves:** owner edits source-language text → TranslatePress machine-translates → an AI agent reviews, approves or corrects translations in several languages at once.
- **Environments:** dev = a test site with free TranslatePress that installs the rolling `dev-latest` build of `develop`; production = tagged releases from `main`. Both update through the bundled Plugin Update Checker (§10). **No local WordPress or Docker required**; CI runs on GitHub Actions.

---

## 4. Stage 0 — Discovery (no plugin code)

Download TranslatePress **3.3.8** from wordpress.org; read WordPress core 6.9+ for the Abilities API. Write `docs/SCHEMA.md` (concise, with file/line references):

1. Table names and columns for regular strings — [VERIFY] expected `{prefix}trp_dictionary_{default}_{lang}`, `{prefix}trp_original_strings`, `{prefix}trp_original_meta`. How a dictionary row links to `original_id`.
2. Status values — [VERIFY] expected 0 untranslated, 1 machine, 2 human.
3. Meaning of `block_type`.
4. How originals are normalised when stored (trimming, whitespace, HTML entities) — needed for exact-match lookup.
5. The method TranslatePress's editor uses to save a translation; can it be called directly? Preferred: call it. Fallback: `$wpdb` + replicate side effects.
6. Hooks fired on save (claims about `trp_translate_string` / `trp_get_translation` are unverified).
7. Translation caching and how to invalidate after a write (object cache may be Redis).
8. What `trp_original_meta` records: can a string's **context** (post ID, URL, title/meta/content) be derived **with free TranslatePress only**? Note whether SEO meta-tag translation involves a paid add-on.
9. Whether a reset (→ untranslated) is machine-translated again on the next page view when automatic translation is on.
10. Where automatic-translation settings are stored (option name, keys for enabled / engine / languages) and which keys are secrets that must never be returned.
11. Whether editing an original row breaks matching (justifies "never write originals").
12. Abilities API: exact signatures and hooks for `wp_register_ability` / `wp_register_ability_category`, how `permission_callback`, `input_schema`, `output_schema`, `annotations` and `meta` are declared, and how errors are returned.
13. For LATER only, one paragraph each: gettext storage, orphan detection, any built-in cleanup routine.

**Gate:** owner reviews `docs/SCHEMA.md` before Stage 1.

**Status: done (2026-10-23).** Findings in `docs/SCHEMA.md`; wider notes in `docs/TRANSLATEPRESS-INTERNALS.md` (read it before touching TP internals). Owner decisions on the open questions:

| | Decision |
|---|---|
| A | Several dictionary rows per `original_id` + language: read and write **the row TranslatePress renders** (Stage 1 confirms which one from `class-query.php:118-138` and records it in INTERNALS). Report the extra rows with warning `sat_duplicate_rows`. Never delete them. |
| B | Status `3` (similar translated) is reported and counted as `machine`; `approve` turns it into `human`. |
| C | `block_type IN (0, 1)` only; never change `block_type`. |
| D | Do not fire `trp_save_editor_translations_regular_strings`. |
| E | No `context` in v1. |
| F | `get-auto-translation-settings` also returns `manual_only` (the free `manual_translation_only` advanced setting). |
| G | Text lookups use **normalised comparison** (see §5.4), because originals are stored as emitted in the page HTML (entities, typographic quotes). Stage 1 verifies on the dev site how a typed apostrophe is stored. |

---

## 5. Scope v1 — seven abilities

Regular (dictionary) strings only, `block_type IN (0, 1)`. Items are identified by **`original_id` + `language`** throughout. Statuses exposed: `untranslated` (0), `machine` (1 and 3), `human` (2).

All abilities (SCHEMA §12):
- registered on `wp_abilities_api_categories_init` / `wp_abilities_api_init`;
- object `input_schema` with `additionalProperties: false` (top-level `default` for no-argument abilities), `output_schema`;
- `meta`: `show_in_rest: true`, `mcp: { public: true }`, `annotations` — reads `readonly: true` (GET); writes `readonly: false, destructive: true, idempotent: false` (POST);
- **callbacks apply property defaults and cast types themselves** (core fills no nested defaults; 6.9 GET input arrives as strings). In particular `dry_run` defaults to `true` **in code**;
- reads use **direct `SELECT`s only** — never `process_strings()`, the editor or String Translation APIs, which insert rows and spend machine-translation quota (SCHEMA §9).

Stages 1 and 2 cover regular (page) strings. Stage 2b extends the same abilities to gettext strings (§5.5); design Stage 1 and 2 code so that adding a `source` parameter later needs no rewrite, but do **not** implement anything for gettext before Stage 2b.

### 5.1 Read — Stage 1

| Ability | Input | Output |
|---|---|---|
| `get-stats` | `language?` | Per language: counts untranslated / machine / human |
| `search-strings` | `language` (required), `status?` (array), `query?` (substring on original or translation, normalised per §5.4 and **case-insensitive**), `page`, `per_page` (≤ 50) | Items `original_id`, `original`, `translated`, `status`; paging (`page`, `per_page`, `total`, `truncated` when the §5.4 candidate cap was hit) |
| `get-strings` | exactly one of `original_ids[]` or `originals[]` (text, ≤ 20 each, matched per §5.4); `languages?` | Per original: `original_id`, `original` (as stored), `translations` `{ lang: { translated, status } }`, `warnings[]` (e.g. `sat_duplicate_rows`); plus `not_found[]` |
| `get-auto-translation-settings` | — | `enabled`, `manual_only`, `engine`, `languages` (translation languages minus default, **not** checked against engine support: TP's engine code mapping can make an HTTP call, owner decision at Stage 1, INTERNALS §10 item 18). Read with `get_option` and an explicit key allowlist; no TP method calls. **Never** API keys, license data or notification email |

### 5.2 Write — Stage 2

**`update-translations`**

Input:
- `items[]` (≤ 100), each `{ original_id, language, action, translated?, expected_current, overwrite_reviewed? }`
  - `action`: `translate` (requires `translated`), `approve` (machine → human, text unchanged), `reset` (→ untranslated: writes `status = 0` and `translated = ''`; with automatic translation on and `manual_only` off, the string is machine-translated again on its next page view)
  - `expected_current`: the translation the caller last read (empty string if untranslated). **Required.**
- `protected_terms[]?` — strings that, when present in the original, must appear verbatim in the translation
- `note?` (≤ 200 chars)
- `dry_run` — **default `true`**

Rules:
- Target language must be configured and not the default language.
- `expected_current` ≠ current value → item `conflict`, not written.
- Current status human + `translate`/`reset` requires `overwrite_reviewed: true`, else `sat_reviewed_protected`.
- `approve` on an untranslated row → error; on a human row → outcome `unchanged`.
- Originals are never written.
- Errors block the item; warnings never block.

Write mechanics (SCHEMA §5, §11):
- Target row per decision A. Write with one conditional statement that can never insert:
  `UPDATE {dict} SET translated = %s, status = %d WHERE id = %d AND original_id = %d AND BINARY COALESCE(translated,'') = %s AND status = %d` — 0 rows affected → `conflict`. This makes `expected_current` atomic. `NULL` compares as `''`.
- Never write `original` or `block_type`. Never insert or delete rows.
- Final text = `Validator` → `wp_kses` (allowlist from the original's tags) → `trp_sanitize_string()`. If that pipeline changes the text, the item is rejected with `sat_sanitization_changed` (e.g. `%xx` sequences, which TranslatePress strips) rather than storing something different from what was validated.
- Table names only via `TRP_Query::get_table_name()` for codes in `trp_settings['translation-languages']` minus the default.
- No cache invalidation (TP's only cache group is request-scoped). Full-page caches are outside the plugin; README says so in one line.

Output: `dry_run`, `batch_id` (only when written), `items[]` with `original_id`, `language`, `outcome` (`would_apply` | `applied` | `unchanged` | `rejected` | `conflict`), `errors[]`, `warnings[]`, `old` `{ translated, status }`, `new` `{ translated, status }`.

**`revert`** — input exactly one of `change_id` or `batch_id`. Restores previous value **and** status for each change; each restoration is logged as a new change in a new batch. Per-change `conflict` if the row changed since.

**`get-change-log`** — input `language?`, `batch_id?`, `since?`, `page`, `per_page` (≤ 50). Output: entries with all §6 columns.

### 5.3 Validation (pure PHP, `Validator` class)

Errors (block the item):
- `sat_html_mismatch` — tag names or attribute names differ from the original; or `href`/`src`/`action`/`style`/`on*` attribute values differ. After the check, output is passed through `wp_kses` with an allowlist built from the original's tags (done by the caller of `Validator`, not inside it).
- `sat_placeholder_mismatch` — printf tokens (`%s`, `%d`, `%1$s`…) count differs.
- `sat_too_long` — > 10 000 characters. `sat_invalid_utf8`.

Warnings (never block):
- `sat_numbers_mismatch` — a digit sequence appears in the translation but not in the original, or vice versa (catches invented dates, dropped prices).
- `sat_identical_to_original` — translation equals the original (likely missed translation).
- `sat_protected_term_missing` — a `protected_terms` entry present in the original is absent or altered in the translation (case-sensitive).

Raised outside `Validator`: `sat_sanitization_changed` (error), `sat_duplicate_rows` (warning). Ability-level errors (`WP_Error`): `sat_invalid_language`, `sat_invalid_input` (e.g. both or neither of `original_ids` / `originals`), `sat_not_found`, `sat_writes_disabled`, `sat_schema_unsupported`.

### 5.4 Text normalisation (pure PHP, unit-tested)

Originals are stored exactly as emitted in the page HTML: text nodes keep entities (`&amp;`, `&#8217;`), attributes are decoded (SCHEMA §4). Text a person or agent types will therefore rarely match byte for byte.

`normalize(string): string` — decode HTML entities, map typographic apostrophes/quotes/dashes, the ellipsis `…` (→ `...`) and non-breaking spaces to their plain equivalents, collapse internal whitespace, trim. Case and accents are kept. `search-strings` additionally compares lower-cased (`mb_strtolower`); `get-strings` stays case-sensitive because it identifies a precise string.

- `get-strings` `originals[]`: find candidates in `trp_original_strings` with a `LIKE` on the longest word of the input (≥ 4 characters, entity-free, cap 200 candidates), then keep those whose `normalize(original)` equals `normalize(input)`. Several matches → return them all.
- `search-strings` `query`: same candidate approach on the dictionary, filtered in PHP with normalised `str_contains` on original and translation.
- The ability descriptions state that matching is normalised, not exact.

---

### 5.5 Plugin and theme strings (gettext) — Stage 2b

Strings that plugins and themes pass through WordPress translation functions (`__()`, `_e()`, `_x()`, `_n()`…) are stored by TranslatePress in separate gettext tables (SCHEMA §13). Third-party and the owner's own plugins are treated identically.

**Stage 2b discovery first** (add findings to `docs/SCHEMA.md` §14 and INTERNALS), with file/line references:
1. Exact gettext table and column names in TP 3.3.8; how a row is addressed (`original_id`, `plural_form`, `domain`, `context`).
2. How rendering picks a translation: TP table vs the plugin's own `.mo` file (status `4`). Does writing status `2` in the TP table override a `.mo` translation?
3. Statuses used in gettext tables, and whether rows are inserted at render time (quota / side effects for reads).
4. Plural forms: how many rows per original per language, and how the plural count is determined.
5. How the gettext editor saves (sanitisation, hooks, duplicates), to mirror §5.2 write mechanics.
6. Placeholder conventions in gettext strings (`%s`, `%1$s`, `%d`) — `Validator` placeholder parity becomes critical here: a broken placeholder can trigger a PHP error in the plugin.

**Scope (no new abilities):**
- Add optional `source`: `regular` (default) | `gettext` to `get-stats`, `search-strings`, `get-strings`, `update-translations` (per item), `get-change-log`.
- Gettext items are identified by `original_id` + `plural_form` (default 0) + `language`; outputs add `domain`, `context`, `plural_form`.
- Add optional `domain` filter to `get-stats` and `search-strings` (e.g. list everything from one plugin).
- `get-strings` by text with `source: gettext` matches on `original` and optional `domain` / `context`.
- Same rules as §5.2–5.4 (dry run default, `expected_current`, `overwrite_reviewed`, Validator, sanitisation, change log, revert).
- Change log gains `source`, `plural_form` (nullable for regular).
- Schema guard covers the gettext columns; on mismatch only gettext writes are disabled.

Scope may shrink after the discovery (e.g. leave plurals read-only) — any reduction is recorded here before coding.

## 6. Data

One table `{prefix}sat_changes`: `id`, `batch_id` (UUID), `created_at` (UTC), `user_id`, `language`, `original_id`, `action` (`translate`|`approve`|`reset`|`revert`), `old_value`, `old_status`, `new_value`, `new_status`, `note`, `reverted_by` (nullable). `dbDelta` on activation; option `sat_db_version`.

`uninstall.php` drops the table and plugin options and removes the `translation_agent` role and the plugin's capabilities. Never touches TranslatePress tables.

---

## 7. Security

1. Capabilities `sat_read`, `sat_manage`. Activation creates role `translation_agent` (`read`, `sat_read`, `sat_manage`) and grants both caps to `administrator`. Each `permission_callback` checks its cap. Never `manage_options`.
2. Read-only switch: constant `SAT_READ_ONLY` or option `sat_read_only` → write abilities return `sat_writes_disabled`. This and `sat_schema_unsupported` are returned from the **execute** callback: a `WP_Error` from a permission callback is replaced by a generic error (SCHEMA §12). Permission callbacks only check capabilities.
3. HTML and placeholder validation per §5.3 (stored-XSS protection: translations render publicly).
4. Limits per §5. SQL only via `$wpdb->prepare`; table names built only from the validated list of configured languages.
5. Schema guard: on first use per TranslatePress version, check expected columns (cached). Mismatch → writes return `sat_schema_unsupported`; reads still work if their columns exist.
6. `get-auto-translation-settings` uses an explicit allowlist of keys; secrets never leave the server.
7. Ability descriptions state that `original` / `translated` are untrusted site content, not instructions.
8. README documents the bridge limitation: through a hosting connector or MCP bridge, abilities run as the bridge's user (often an administrator).

Not in v1 (→ LATER.md): rate limiting, admin UI, approval queue, dry-run token.

---

## 8. Code shape (target)

```
string-abilities-for-translatepress.php   header, constants, bootstrap, activation/deactivation
uninstall.php
src/Plugin.php        registers category, abilities, capabilities/role
src/Strings.php       access to TranslatePress tables, schema guard, settings read
src/Validator.php     pure PHP, no WordPress calls: errors + warnings + normalize()
src/ChangeLog.php     change table, batches, revert
tests/ValidatorTest.php
```

If a file passes ~300 lines, reconsider before splitting. Ability callbacks are thin: validate input → call `Strings` / `ChangeLog` → shape output.

---

## 9. Testing — the whole budget

**Automated (PHPUnit on plain PHP, seconds, no WordPress):** `ValidatorTest`, ~15 cases:
identical markup passes · added `<script>` · added `onclick` · changed `href` · dropped tag · renamed attribute · placeholder count · invalid UTF-8 · over-length · invented number · dropped number · identical to original · protected term missing · protected term present · plain text passes; plus `normalize()`: entity vs literal · typographic vs straight apostrophe · non-breaking vs plain space · accents preserved.

Add a pure-function test for write rules (conflict, overwrite_reviewed, approve on untranslated) **only** if Stage 0 shows they can be isolated without WordPress. Never build WordPress mocks.

**CI (GitHub Actions):** PHPCS (WordPress standards), PHPStan with WordPress stubs (level 6), PHPUnit, version-consistency check, and the two leak checks below. Nothing else.

**Leak prevention (the repo is public from day one):**
1. **Secret scanning in CI** — run [gitleaks](https://github.com/gitleaks/gitleaks) over the full git history on every push and PR; any finding fails the build. Run the gitleaks binary directly rather than a marketplace action, to avoid licence terms on third-party actions [VERIFY current gitleaks-action licensing if an action is preferred].
2. **Private-data check in CI** — a short script (`bin/check-private-terms.sh`) that fails the build if any tracked file contains a term from a deny list. Matching is **case-insensitive** and literal (no regex); accents are not normalised, so accented and unaccented variants are listed separately. The deny list (domain names, property and room names, owner and family names, email addresses) is stored **only** in the GitHub Actions secret `SAT_PRIVATE_TERMS` (one term per line), never in the repo. The script must not print the matched term, only the file and line number. If the secret is absent (forks), the step is skipped with a notice.
3. **Locally**, the same two checks run as pre-commit hooks (`.pre-commit-config.yaml`: gitleaks hook + the private-terms script reading a git-ignored `.private-terms` file).
4. **On GitHub** (owner, repo settings): secret scanning and **push protection** enabled.

A leak found after a push is handled by rotating the credential first, then rewriting history; deleting the file is not enough.

**Manual smoke test** (`docs/SMOKE-TEST.md`, ≤ 15 steps, ≤ 15 min), run on the dev site through the hosting connector's `execute_ability`: abilities listed · stats · search · get-strings by id, by exact text and by text typed with a straight apostrophe · auto-translation settings (no secrets) · dry run with a warning · apply translate · front end shows it (purge or bypass any page cache first) · TranslatePress editor shows human status · approve · overwrite human without flag rejected · stale `expected_current` → conflict · revert batch · read-only switch.

---

## 10. Conventions

First read `github.com/jacamac/beds24-availability-calendar` (published as *Availability Calendar for Beds24*; repo may have been renamed) and mirror its structure and workflows. Where it differs from below, follow it and note the difference in `CLAUDE.md`.

- **Versioning:** SemVer from `0.1.0`. `Version:` header = `SAT_VERSION` = `Stable tag:` in `readme.txt` (CI check).
- **Header:** Plugin Name, Plugin URI, Description, Version, Requires at least 6.9, Requires PHP 8.1, Requires Plugins, Author `jacamac`, Author URI https://github.com/jacamac, License GPL-2.0-or-later, License URI, Text Domain. No Git Updater headers.
- **Updates — mirror the reference repo exactly:** bundle Plugin Update Checker in `vendor/` as the reference plugin does (same library, same version constraint, same integration pattern), pointing at this repo's GitHub releases. This is the only runtime dependency; it is the owner's standard across plugins. `dev-release.yml` (as in the reference repo) publishes a rolling `dev-latest` pre-release zip on every push to `develop` that passes CI; production installs only tagged releases. If the plugin is ever submitted to wordpress.org, that build must exclude the update checker (wordpress.org does not allow self-updating plugins [VERIFY current guideline]).
- **Branches:** `develop` (dev site, `dev-latest` builds) / `main` (production, tagged releases).
- **Release:** merge `develop` → `main`, bump version, move `Unreleased` to the new version in `CHANGELOG.md`, sync `readme.txt`, commit `Release vX.Y.Z`, tag `vX.Y.Z`. `release.yml` builds a clean zip (no `.git`, `.github`, `tests`, `docs`, dev config, dev dependencies) and creates the GitHub Release with that version's changelog section as body.
- **Release notes:** `CHANGELOG.md`, Keep a Changelog format; every behaviour change adds an entry under Added / Changed / Fixed / Removed / **Security**.
- **Repo files:** `README.md`, `readme.txt`, `CHANGELOG.md`, `SECURITY.md`, `LICENSE` (full GPL-2.0 text), `CLAUDE.md`, `docs/`, `.github/workflows/{ci,release}.yml`, `bin/check-private-terms.sh`, `.pre-commit-config.yaml`, `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.gitignore`, `.gitattributes` (export-ignore dev files).
- **README is the ability reference.** Keep it in sync with the code in the same commit as any ability change.

---

## 11. Stages

| Stage | Version | Content | Exit criteria |
|---|---|---|---|
| 0 Discovery | — | `docs/SCHEMA.md` | Owner approves |
| 1 Read-only | 0.1.0 | Tooling, CI, release workflow, bootstrap, caps/role, schema guard, 4 read abilities | Read part of smoke test passes on dev; installed on production, stats consistent with the TranslatePress editor |
| 2 Write | 0.2.0 | Validator + tests, change log, `update-translations`, `revert`, `get-change-log`, read-only switch | Full smoke test on dev; on production after a backup: one small multi-language batch verified on front end and in the editor, reverted, re-applied |
| 2b Plugin strings | 0.3.0 | Short discovery (§5.5) → `source: gettext` on existing abilities, `domain` filter, change-log columns, Validator reused | Smoke test with one plugin string per action on dev, including a placeholder string; on production: one batch on one plugin's strings, verified on the front end, reverted, re-applied |
| 3 Public | 1.0.0 | Review pass, final README / readme.txt / SECURITY.md, repo public | Owner sign-off; then contact Cozmoslabs |
| Later | — | See `docs/LATER.md` | Only on demonstrated need |
