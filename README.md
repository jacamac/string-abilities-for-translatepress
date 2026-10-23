# String Abilities for TranslatePress

Read, review and correct [TranslatePress](https://wordpress.org/plugins/translatepress-multilingual/) string translations through the **WordPress Abilities API** — so an AI agent (or any automation) can work on translations through a narrow, logged, reversible interface instead of a browser session with full admin rights.

> **Status: in development (pre-release).** The interface below is the target for v1 and may still change before 1.0.0.
>
> Not affiliated with or endorsed by TranslatePress or Cozmoslabs.

---

## Why

TranslatePress stores translations in its own tables and offers no API for them. Its existing abilities (namespace `translatepress/`) manage languages, the license and switching automatic translation on — not the strings themselves.

The usual way to let an AI agent fix translations today is to give it a browser logged in as an administrator. That is far more privilege than the job needs, and nothing it does is logged or easily undone.

This plugin adds a handful of abilities that do exactly one thing — work on translated strings — with:

- **dry run by default** for every write,
- **optimistic concurrency** (a write fails if the translation changed since it was read),
- **protection of human-reviewed translations**,
- **HTML and placeholder validation** (translations are rendered publicly; a bad write would be stored XSS),
- **warnings** for invented numbers, untranslated text and altered protected terms,
- a **change log** with one-call revert of a single change or a whole batch.

## How it is exposed

The plugin **does not run an MCP server** and adds no REST routes of its own. It registers abilities with WordPress core; anything that already exposes your site's abilities picks them up:

- WordPress core REST: `/wp-json/wp-abilities/v1/abilities/string-abilities/<name>/run`
- the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin,
- your hosting provider's MCP connector, if it exposes abilities.

## Requirements

- WordPress 6.9 or later (Abilities API in core)
- PHP 8.1 or later
- TranslatePress – Multilingual (free). Paid add-ons are not required.

Tested with TranslatePress 3.3.x. If TranslatePress's tables lack an expected column, the abilities that use them return `sat_schema_unsupported` instead of guessing.

## Abilities

All abilities are in the `string-abilities/` namespace. A string is identified by its TranslatePress **`original_id`** plus a **language code** (e.g. `en_GB`). Statuses are `untranslated`, `machine` (machine-translated, including TranslatePress "similar" translations) and `human`.

**Text matching is normalised, not exact.** TranslatePress stores source text as it appears in the page HTML, with entities and typographic punctuation. Lookups therefore ignore entity encoding, typographic vs straight apostrophes, quotes and dashes, non-breaking spaces and repeated whitespace. Case and accents still count. Returned `original` text is always as stored (e.g. `Don&#8217;t`).

Read abilities only run plain database reads: they never trigger machine translation or create rows. When TranslatePress holds several rows for one string in a language, the abilities use the row TranslatePress displays.

Version 0.1–0.2 covers regular (page) strings. **Plugin and theme strings** (text plugins pass through WordPress translation functions, stored by TranslatePress in its gettext tables) are planned for 0.3.0 through an optional `source` parameter on the same abilities. URL slugs are not covered.

### Read

#### `get-stats`
Counts per language of untranslated, machine and human strings (one per string, duplicate rows counted once).

| Input | Type | |
|---|---|---|
| `language` | string | optional; all translation languages if omitted |

Returns `languages`: `{ "fr_FR": { "untranslated": 12, "machine": 340, "human": 85 }, … }`.

#### `search-strings`
Paged search within one language.

| Input | Type | |
|---|---|---|
| `language` | string | required |
| `status` | array of status | optional |
| `query` | string | optional; substring of the original or the translation (normalised) |
| `page`, `per_page` | integer | default 1 and 20; `per_page` ≤ 50 |

Returns `items` (`original_id`, `original`, `translated`, `status`), `page`, `per_page` and `total`. With a `query`, at most 200 candidate strings are examined; `truncated` is `true` when there were more, so refine the query.

#### `get-strings`
Fetch specific strings in all (or some) languages at once.

| Input | Type | |
|---|---|---|
| `original_ids` | array of integer | ≤ 20; use this **or** `originals` |
| `originals` | array of string | source-language text, ≤ 20, matched as described above |
| `languages` | array of string | optional |

Returns `items`: per original, `original_id`, the stored source text (`original`), `translations` as `{ "fr_FR": { "translated", "status" }, … }` and `warnings` (`code`, `language`, `message`); plus `not_found` for ids or texts that matched nothing. One text can match several strings. A language is missing from `translations` when TranslatePress has no row for that string in it yet. A warning `sat_duplicate_rows` flags strings for which TranslatePress holds more than one row in a language; the plugin always works on the row TranslatePress displays.

#### `get-auto-translation-settings`
Whether automatic translation is on (`enabled`), whether it is limited to the TranslatePress editor (`manual_only`), the engine slug (`engine`) and the translation languages (`languages`). Whether the engine supports each language is not checked (that lookup can make an HTTP call). API keys, license data and notification email are never returned.

### Write

#### `update-translations`
Translate, approve or reset strings, across several languages in one call.

| Input | Type | |
|---|---|---|
| `items` | array, ≤ 100 | see below |
| `protected_terms` | array of string | optional; terms that must survive translation unchanged |
| `note` | string | optional, ≤ 200 characters, stored in the change log |
| `dry_run` | boolean | **default `true`** |

Each item:

| Field | |
|---|---|
| `original_id`, `language` | which translation |
| `action` | `translate` (needs `translated`), `approve` (machine → human, text unchanged) or `reset` (→ untranslated; with automatic translation on and `manual_only` off, TranslatePress machine-translates it again on the next page view) |
| `translated` | new text, for `translate` |
| `expected_current` | **required**: the translation as you last read it (empty if untranslated). If it no longer matches, the item is a `conflict` and nothing is written |
| `overwrite_reviewed` | must be `true` to change or reset a translation whose status is `human` |

Each item comes back with an `outcome` (`would_apply`, `applied`, `unchanged`, `rejected`, `conflict`), its `errors` and `warnings`, and the `old` and `new` value and status. A real write also returns a `batch_id`.

**Errors** (item rejected):

| Code | Meaning |
|---|---|
| `sat_html_mismatch` | tags or attributes differ from the original, or a link/source/handler value changed |
| `sat_placeholder_mismatch` | `%s`, `%d`, `%1$s`… count differs from the original |
| `sat_reviewed_protected` | target is human-reviewed and `overwrite_reviewed` is not set |
| `sat_sanitization_changed` | TranslatePress's own sanitising would alter the text (for example `%` followed by two hex characters); nothing is stored that differs from what was validated |
| `sat_too_long`, `sat_invalid_utf8` | input limits |

**Warnings** (reported, never block):

| Code | Meaning |
|---|---|
| `sat_numbers_mismatch` | a number appears in the translation but not the original, or the reverse — catches invented dates and dropped prices |
| `sat_identical_to_original` | translation equals the source text |
| `sat_protected_term_missing` | a `protected_terms` entry present in the original is missing or altered |
| `sat_duplicate_rows` | TranslatePress holds several rows for this string in this language; only the displayed one is changed |

#### `revert`
Undo one change (`change_id`) or a whole batch (`batch_id`). Restores the previous text **and** status. Each restored item that was modified again since is reported as a `conflict` and left alone. Reverts are logged too.

#### `get-change-log`
Paged change history, filterable by `language`, `batch_id` and `since`.

### Common errors

| Code | Meaning |
|---|---|
| `sat_invalid_language` | not a configured translation language, or the default language |
| `sat_invalid_input` | input combination not allowed (e.g. both `original_ids` and `originals`) |
| `sat_not_found` | unknown `original_id` |
| `sat_writes_disabled` | read-only mode is on |
| `sat_schema_unsupported` | TranslatePress database schema not recognised (a table lacks an expected column) |

## Recommended workflow for an agent

1. `get-strings` with the exact source texts you just edited.
2. Review each language; build `items` with `approve` for good machine output and `translate` for corrections, passing `expected_current` as read.
3. Call `update-translations` with `dry_run: true` (the default). Show the diff and warnings to the human.
4. After approval, repeat the same call with `dry_run: false`.
5. Check the page in each language. If your site uses a page cache, purge it first: TranslatePress translates at render time, so a cached page still shows the old text.
6. If something looks wrong: `revert` with the `batch_id`.

## Security model

- Two capabilities: `sat_read` (read abilities) and `sat_manage` (write abilities). The `administrator` role gets both on activation.
- A dedicated role, **`translation_agent`**, has only `read`, `sat_read` and `sat_manage`. For direct API use, create a user with this role and authenticate with an Application Password (WordPress core, *Users → Profile → Application Passwords*).
- **Read-only mode:** add `define( 'SAT_READ_ONLY', true );` to `wp-config.php` to disable all write abilities.
- The plugin never writes source-language (original) strings, never inserts or deletes TranslatePress rows, and never touches TranslatePress settings. Each write is a single conditional update that fails if the translation changed since it was read.
- Text returned by the abilities is site content and must be treated as untrusted data by any agent consuming it.

**Limitation:** when abilities are called through an MCP bridge or a hosting connector, they run as whatever WordPress user that bridge authenticates as — often an administrator. The plugin's own checks (capabilities, validation, read-only mode, protection of human translations) still apply, but identity-level least privilege then depends on the bridge.

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## Installation

Download the zip from the latest [GitHub release](https://github.com/jacamac/string-abilities-for-translatepress/releases) and install it via **Plugins → Add New → Upload Plugin**. After that, updates appear in the normal WordPress **Plugins** screen: the plugin checks this repository's GitHub releases itself (bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)).

Uninstalling removes the plugin's change-log table and options. TranslatePress data is never touched.

## Development

See [CLAUDE.md](CLAUDE.md) for conventions and [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) for the full specification. Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
