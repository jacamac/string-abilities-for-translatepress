# Smoke test

Manual check on the dev site (free TranslatePress), through the hosting connector's `execute_ability` or `/wp-json/wp-abilities/v1/abilities/string-abilities/<name>/run` (GET for reads). About 10 minutes for reads, 15 for writes. Use real site strings while testing, but never copy them into the repo, an issue or a commit message.

Record the plugin version, WordPress and TranslatePress versions, and the date in the release notes of your own checklist, not here.

## Read (Stage 1, 0.1.0)

1. **Abilities listed.** The four `string-abilities/` abilities appear in the connector's ability list (or `GET /wp-json/wp-abilities/v1/abilities?category=string-abilities`).
2. **Stats.** `get-stats` with no input returns every translation language and no default language. Pick one language and compare its counts with the TranslatePress String Translation page (filter by status). `get-stats` with the default language returns `sat_invalid_language`.
3. **Search.** `search-strings` with `language` and `per_page: 5` returns 5 items and a `total`. Page 2 returns the next ones. `status: ["human"]` returns only human items. A `query` with a word from a known translation finds it.
4. **get-strings by id.** Pass two `original_ids` from step 3 and one unused id (e.g. `999999999`). Both strings come back with every language, and the unused id is in `not_found`.
5. **get-strings by exact text.** Pass the `original` of an item from step 3 exactly as returned. It is found.
6. **get-strings by typed text (decision G).** Find a string with an apostrophe or quote. Check how it is stored: the `original` from step 3 shows `&#8217;`, `’` or `'`. Note which, then pass the text typed with a straight apostrophe `'`. It is found. Repeat with a string containing `&` or a non-breaking space if one exists.
7. **No side effects.** Before and after steps 2–6, `get-stats` returns the same counts (reads create no rows and trigger no machine translation).
8. **Auto-translation settings.** `get-auto-translation-settings` returns `enabled`, `manual_only`, `engine` and `languages`, which match TranslatePress → Settings → Automatic Translation. The output contains no API key, license key or email address.

## Write (Stage 2, 0.2.0)

Writes are POST (`update-translations`, `revert`); `get-change-log` is GET. Purge or bypass any page cache before each front-end check. Pick two languages `L1` and `L2` and, with `get-strings`, a string `A` that is machine-translated in both, a string `B` that is human in `L1`, and an `original_id` `C` that has no row in `L2` (e.g. a string seen only in `L1`; compare `translations` of a few ids). Note each `translated` value: it is the `expected_current` below.

1. **Abilities listed.** `update-translations`, `revert` and `get-change-log` appear next to the four read abilities. Plugins screen shows `0.2.0-dev.N`.
2. **Dry run with a warning.** `update-translations` without `dry_run`, one item: `A` in `L1`, `action: translate`, a corrected text that adds a number absent from the original. Output: `dry_run: true`, outcome `would_apply`, warning `sat_numbers_mismatch`, no `batch_id`. `get-strings` for `A` shows the old text.
3. **Apply on several languages in one call.** Same call with `dry_run: false` and two items: `A` in `L1` (`translate`, a text without invented numbers) and `A` in `L2` (`translate`). Both `applied`; one `batch_id` returned. Write it down.
4. **Front end and editor.** Open the page with `A` in `L1` and `L2`: the new texts show. In the TranslatePress editor, `A` is human-reviewed in both.
5. **Approve.** Find another machine string `D` in `L1`; `approve` it with `dry_run: false`. Outcome `applied`, `new.status: human`, same text. Approving it again: `unchanged`.
6. **Human translation protected.** `translate` `B` in `L1` with `dry_run: false` and no `overwrite_reviewed`: outcome `rejected`, error `sat_reviewed_protected`. With `overwrite_reviewed: true` (keep `dry_run` default): `would_apply`.
7. **Stale `expected_current`.** Repeat step 3's item for `A` in `L1` with the old `expected_current` from before step 3: outcome `conflict`, nothing written.
8. **No row.** `translate` `C` in `L2`: outcome `rejected`, error `sat_not_found` with the "viewed in that language" message. `get-stats` for `L2` unchanged.
9. **Unsafe HTML.** With the current `expected_current` (a stale one is a `conflict` before any check), `translate` `A` in `L1` with a `<script>` tag or an `onclick` added: `rejected`, `sat_html_mismatch`. With a bare `&` (dry run): `would_apply`, warning `sat_ampersand_encoded`, `new` shows `&amp;`. With `%ab` in the text: `rejected`, `sat_sanitization_changed`.
10. **Change log.** `get-change-log` with step 3's `batch_id`: two entries, action `translate`, old and new values and statuses, `reverted_by: null`.
11. **Revert a batch.** `revert` with step 3's `batch_id`: both `applied`, a new `batch_id`. `get-strings` and the front end show the original machine texts with status `machine`. `get-change-log` shows two `revert` entries; the step 3 entries now have `reverted_by`. The same `revert` again: both `unchanged`.
12. **Read-only switch.** `wp option update sat_read_only 1` (or `define( 'SAT_READ_ONLY', true );`): `update-translations` with `dry_run: false` and `revert` return `sat_writes_disabled`; a dry run still works and returns `writes_disabled: true`; reads still work. Set it back to `0`.

## Plugin strings (Stage 2b, 0.3.0)

Same setup as Stage 2. With `search-strings` (`source: "gettext"`, `language: L1`) pick a plugin's text domain `D` that has no translation file for `L1` and appears on a public page, and in `D`: a string `P` containing a `%s` or `%d` placeholder (machine or untranslated), a string `M` with status `language_file` (from any domain that ships a `.mo`), and, if one exists, a plural string `N` (non-null `original_plural`) with a row for `plural_form` 1. Note each `translated` as `expected_current`.

1. **Stats and filter.** `get-stats` with `source: "gettext"` returns four counts per language, including `language_file`; with `domain: D` the counts are smaller. `get-stats` with `domain` but no `source` returns `sat_invalid_input`. Plugins screen shows `0.3.0-dev.N`.
2. **Read a string.** `get-strings` with `source: "gettext"`, `originals: [<P's original>]`, `domain: D`: one item with `domain`, `context`, `plural_form: 0`. For `N` by `original_ids`, items for each plural form with a row.
3. **Placeholder parity (dry run).** `translate` `P` in `L1` with the placeholder changed (`%s` → `%d`, or `%s %s` → `%1$s %2$s`): `rejected`, `sat_placeholder_mismatch`. With the same tokens reordered or kept: `would_apply`.
4. **Apply and front end.** Same item with `dry_run: false`: `applied`, `batch_id`. Purge the page cache; the page showing `P` in `L1` shows the new text with the placeholder filled in, and no PHP warning in the log.
5. **Override a `.mo` translation.** `approve` `M` (dry run): `unchanged`. `reset` `M`: `rejected`, `sat_invalid_input` with the `.mo` message. `translate` `M` with `dry_run: false` (no `overwrite_reviewed`): `applied`; the front end shows the new text instead of the `.mo` one.
6. **Plural form.** If `N` exists: `translate` `N` `plural_form: 1` with a text matching `original_plural`'s placeholders (dry run): `would_apply`; `plural_form: 5`: `rejected`, `sat_not_found`.
7. **Change log and revert.** `get-change-log` with `source: "gettext"` shows the step 4–5 entries with `source: gettext` and `plural_form: 0`. `revert` both batches: `applied`; the front end shows the original texts again (`M` from the `.mo`), and `get-strings` shows the old statuses.
8. **Regular strings unaffected.** `get-stats` without `source` returns the same three counts as before the update; one Stage 2 dry run (`translate` `A`) still returns `would_apply` with `source: regular` in the item.
