# String Abilities for TranslatePress — Requirements

WordPress plugin that registers a small set of **WordPress Abilities** for reading, reviewing and correcting TranslatePress string translations. It ships **no MCP server and no REST routes**: abilities are exposed by whatever already exposes the site's abilities (core `/wp-json/wp-abilities/v1/`, a hosting provider's MCP connector, the MCP Adapter plugin).

> Audience: Claude Code. Owner: Jacques Leisy (GitHub `jacamac`). GPL-2.0-or-later, to be published.
> Spec revision 4 (2026-10-23).
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
- **Environments:** dev = a hosted test site with free TranslatePress, Git Updater tracking `develop`; production = Git Updater tracking `main`. **No local WordPress or Docker required**; CI runs on GitHub Actions.

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

---

## 5. Scope v1 — seven abilities

Regular (dictionary) strings only. All abilities: `input_schema` with `additionalProperties: false`, `output_schema`, accurate `annotations`, `show_in_rest: true`, `meta.mcp.public: true`. Items are identified by **`original_id` + `language`** throughout.

### 5.1 Read — Stage 1

| Ability | Input | Output |
|---|---|---|
| `get-stats` | `language?` | Per language: counts untranslated / machine / human |
| `search-strings` | `language` (required), `status?` (array), `query?` (substring on original or translation), `page`, `per_page` (≤ 50) | Items `original_id`, `original`, `translated`, `status`; paging |
| `get-strings` | exactly one of `original_ids[]` or `originals[]` (exact text, ≤ 20 each); `languages?` | Per original: `original_id`, `original`, `translations` `{ lang: { translated, status } }`, `context?` (only if §4.8 allows, free TP only); plus `not_found[]` |
| `get-auto-translation-settings` | — | `enabled`, `engine`, `languages` (as available per §4.10). **Never** API keys or license data |

### 5.2 Write — Stage 2

**`update-translations`**

Input:
- `items[]` (≤ 100), each `{ original_id, language, action, translated?, expected_current, overwrite_reviewed? }`
  - `action`: `translate` (requires `translated`), `approve` (machine → human, text unchanged), `reset` (→ untranslated)
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

---

## 6. Data

One table `{prefix}sat_changes`: `id`, `batch_id` (UUID), `created_at` (UTC), `user_id`, `language`, `original_id`, `action` (`translate`|`approve`|`reset`|`revert`), `old_value`, `old_status`, `new_value`, `new_status`, `note`, `reverted_by` (nullable). `dbDelta` on activation; option `sat_db_version`.

`uninstall.php` drops the table and plugin options. Never touches TranslatePress tables.

---

## 7. Security

1. Capabilities `sat_read`, `sat_manage`. Activation creates role `translation_agent` (`read`, `sat_read`, `sat_manage`) and grants both caps to `administrator`. Each `permission_callback` checks its cap. Never `manage_options`.
2. Read-only switch: constant `SAT_READ_ONLY` or option `sat_read_only` → write abilities return `sat_writes_disabled`.
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
src/Strings.php       access to TranslatePress tables, schema guard, cache invalidation, settings read
src/Validator.php     pure PHP, no WordPress calls: errors + warnings
src/ChangeLog.php     change table, batches, revert
tests/ValidatorTest.php
```

If a file passes ~300 lines, reconsider before splitting. Ability callbacks are thin: validate input → call `Strings` / `ChangeLog` → shape output.

---

## 9. Testing — the whole budget

**Automated (PHPUnit on plain PHP, seconds, no WordPress):** `ValidatorTest`, ~15 cases:
identical markup passes · added `<script>` · added `onclick` · changed `href` · dropped tag · renamed attribute · placeholder count · invalid UTF-8 · over-length · invented number · dropped number · identical to original · protected term missing · protected term present · plain text passes.

Add a pure-function test for write rules (conflict, overwrite_reviewed, approve on untranslated) **only** if Stage 0 shows they can be isolated without WordPress. Never build WordPress mocks.

**CI (GitHub Actions):** PHPCS (WordPress standards), PHPStan with WordPress stubs (level 6), PHPUnit, version-consistency check, and the two leak checks below. Nothing else.

**Leak prevention (the repo is public from day one):**
1. **Secret scanning in CI** — run [gitleaks](https://github.com/gitleaks/gitleaks) over the full git history on every push and PR; any finding fails the build. Run the gitleaks binary directly rather than a marketplace action, to avoid licence terms on third-party actions [VERIFY current gitleaks-action licensing if an action is preferred].
2. **Private-data check in CI** — a short script (`bin/check-private-terms.sh`) that fails the build if any tracked file contains a term from a deny list. The deny list (domain names, property and room names, owner and family names, email addresses) is stored **only** in the GitHub Actions secret `SAT_PRIVATE_TERMS` (one term per line), never in the repo. The script must not print the matched term, only the file and line number. If the secret is absent (forks), the step is skipped with a notice.
3. **Locally**, the same two checks run as pre-commit hooks (`.pre-commit-config.yaml`: gitleaks hook + the private-terms script reading a git-ignored `.private-terms` file).
4. **On GitHub** (owner, repo settings): secret scanning and **push protection** enabled.

A leak found after a push is handled by rotating the credential first, then rewriting history; deleting the file is not enough.

**Manual smoke test** (`docs/SMOKE-TEST.md`, ≤ 15 steps, ≤ 15 min), run on the dev site through the hosting connector's `execute_ability`: abilities listed · stats · search · get-strings by text and by id · auto-translation settings (no secrets) · dry run with a warning · apply translate · front end shows it · TranslatePress editor shows human status · approve · overwrite human without flag rejected · stale `expected_current` → conflict · revert batch · read-only switch.

---

## 10. Conventions

First read `github.com/jacamac/beds24-availability-calendar` (published as *Availability Calendar for Beds24*; repo may have been renamed) and mirror its structure and workflows. Where it differs from below, follow it and note the difference in `CLAUDE.md`.

- **Versioning:** SemVer from `0.1.0`. `Version:` header = `SAT_VERSION` = `Stable tag:` in `readme.txt` (CI check).
- **Header:** Plugin Name, Plugin URI, Description, Version, Requires at least 6.9, Requires PHP 8.1, Requires Plugins, Author Jacques Leisy, Author URI https://github.com/jacamac, License GPL-2.0-or-later, License URI, Text Domain, `GitHub Plugin URI: jacamac/string-abilities-for-translatepress`, `GitHub Branch: main`, `Primary Branch: main`.
- **Branches:** `develop` (dev site) / `main` (production).
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
| 3 Public | 1.0.0 | Review pass, final README / readme.txt / SECURITY.md, repo public | Owner sign-off; then contact Cozmoslabs |
| Later | — | See `docs/LATER.md` | Only on demonstrated need |
