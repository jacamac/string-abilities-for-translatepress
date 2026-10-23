# TranslatePress internals — working notes

**TranslatePress – Multilingual (free) 3.3.8.** WordPress core 6.9 and 7.1.3 for the Abilities API. These are the sources listed in `docs/SCHEMA.md` (download URLs and checksums are there).

This file collects what was learned from reading the source, beyond the §4 answers in `docs/SCHEMA.md`. Where the two overlap, SCHEMA.md is the decision record and this file is background. **Update this file whenever a session learns something new about TranslatePress or the Abilities API.** When the TranslatePress version changes, re-verify every reference.

**Legend:**
- **[code]**: read in the source at the cited line.
- **[inferred]**: a conclusion drawn from the cited code but not directly stated or exercised. Verify it before relying on it.

**Paths:**
- `TP/` = `translatepress-multilingual/`.
- `WP/` = `wordpress/wp-includes/` in 6.9, unless marked 7.1.
- `Q` = `TP/includes/queries/class-query.php`.
- `R` = `TP/includes/class-translation-render.php`.

---

## 1. Architecture

- **Singleton plus component bag** [code]. `TRP_Translate_Press::get_trp_instance()` (`TP/class-translate-press.php:64`) builds every component once. Its constructor defines `TRP_PLUGIN_VERSION` (`:85`) and registers the `trp` cache group as non-persistent (`:87`). It then calls `initialize_components()`, `define_admin_hooks()` and `define_frontend_hooks()`. Components are fetched with `get_component( 'name' )` (`:104`), which returns the property `$this->$component`.
- **Component construction order** [code] (`TP/class-translate-press.php:181-199`): `TRP_Hooks_Loader`, `TRP_Languages`, `TRP_Settings`, then every other component receives `$settings->get_settings()`, a **snapshot array** taken at construction time. Examples: `TRP_Translation_Render`, `TRP_Url_Converter`, `TRP_Query`, `TRP_Machine_Translator`. The initial `TRP_Machine_Translator` is a placeholder, replaced later by the real engine class (comment at `:199`).
- **Hooks are registered through `TRP_Hooks_Loader`** [code] (`$this->loader->add_action/add_filter`, e.g. `TP/class-translate-press.php:361-547`). Compatibility code and advanced settings remove hooks the same way with `$trp_loader->remove_hook(...)` (`TP/includes/advanced-settings/disable-post-container-tags.php:27`, `:52`). [inferred] Code outside TP must use `TRP_Hooks_Loader`, not `remove_filter()`, because the loader holds the callbacks.
- **Settings shape** [code] (`TP/includes/class-settings.php:400-485`): option `trp_settings` provides `default-language`, `translation-languages`, `publish-languages`, URL slugs and similar. At load time three separate options are merged into the same array: `trp_advanced_settings`, `trp_language_switcher_settings` and `trp_machine_translation_settings` (`:450-470`). TP code reads them as `$settings['trp_machine_translation_settings'][...]`, but they are stored as separate rows in `wp_options`.
- **The current language is the global `$TRP_LANGUAGE`** [code]. It is set from the URL by the language switcher (`TP/includes/class-language-switcher-v2.php:135`) and temporarily swapped by `trp_translate()`, `trp_switch_language()`, `trp_reset_language()` and `trp_restore_language()` (`TP/includes/functions.php:865-881`, `:921-930`, `:1025-1056`). [inferred] Any code that renders or translates must not assume `$TRP_LANGUAGE` matches the request in CLI, cron or REST contexts.

## 2. How a page is translated

1. **Output buffer** [code]. `start_output_buffer()` runs on `init`, priority `trp_start_output_buffer_priority`, default 0 (`TP/class-translate-press.php:447`, `R:38-64`).
   - It does nothing in wp-admin (unless the request is a front-end AJAX call) and in the editor's left pane (`R:44-46`).
   - In the default language it buffers to `render_default_language()`, which only strips TP markers and handles links (`R:50-55`, `R:1859`).
   - Everywhere else it buffers to `translate_page()` (`R:57`).
2. **`translate_page( $output )`** (`R:558`) [code]:
   1. `trp_stop_translating_page` can short-circuit (`R:559`).
   2. `trp_before_translate_content` (`R:571`); `script`/`style` are cut out first (`trp_excluded_tags_from_translation`, `R:574`) and put back afterwards.
   3. JSON output is decoded and translated recursively (`R:643-667`, `translate_json()` at `R:1818`).
   4. The language comes from `force_language_in_preview()` (`R:621`). In the default language regular strings are not translated (`R:632-639`; filter `trp_translate_regular_strings`).
   5. `trp_pre_translating_html` (`R:675`), then the HTML is parsed with TP's **forked simple_html_dom** in namespace `TranslatePress` (`R:695`). The fork renames the text-node tag from `text` to `trptext` (`TP/assets/lib/simplehtmldom/simple_html_dom.php:34-37`, `:120`) and raises the max size to 100 MB (`:73`, `:104`). If parsing fails, the page is returned untranslated with markers stripped (`R:696-701`).
   6. Translation blocks are matched first, against `get_all_translation_blocks()` (`R:704-720`).
   7. Gettext markers already in the HTML (see §4) are resolved (`R:729-860`).
   8. Strings are collected (§3), then `process_strings()` (`R:1105`, defined at `R:2041`) fetches translations, inserts new strings and machine-translates.
   9. Manual-only nodes (external `href`s, media URLs) are looked up but never inserted outside preview (`R:1108-1137`, filter at `R:1111`).
   10. `do_action( 'trp_translateable_information', … )` (`R:1141`).
   11. Post-ID meta is recorded (`R:1143-1176`).
   12. Translations are written into the nodes. Attributes go through `esc_attr()`; text through `trp_sanitize_string()` (`R:1182-1197`).
   13. In preview mode `<translate-press data-trp-translate-id=…>` wrappers are added for the editor (`R:1203-1240`, wrapper built at `R:1205`).
   14. `trp_translated_html` (`R:1275`).
3. **Other entry points** [code]: REST responses (`rest_pre_echo_response` → `handle_generic_rest_api_translations`, `TP/class-translate-press.php:461`, `R:402`), oEmbed (`:462`), and `wp_mail` (`:537`, `R:2550`).
4. **Dynamic (JS-inserted) strings** [code]. The front-end script posts to `wp_ajax(_nopriv)_trp_get_translations_domchanges` (`TP/class-translate-press.php:362-363`). The handler looks up existing rows **by original text only**, never by id, never inserts, and returns no row ids (`TP/includes/class-editor-api-regular-strings.php:86-164`).

## 3. How strings are detected and stored

- **Detection** [code]:
  - Translation blocks: `.translation-block` innertext (`R:903-927`).
  - Text nodes: `trptext` outertext (`R:929-1000`).
  - Attributes: node accessors (`R:1002-1085`; list in `get_node_accessors()`, `R:2324-2410`, filter `trp_node_accessors`). Free accessors are `img[src]`, submit/button `value`, `placeholder`, `[title]`, `a[href]`, `[aria-label]`, video/audio/picture sources, plus `button`/`option` text.
- **Exclusions** [code]: empty after `trp_full_trim`; numeric; `^\d+%$`; parent `script`/`style`/`title`/`textarea`; ancestors with the no-translate attribute; CDATA; `BEGIN:VCALENDAR`; filter `trp_skip_strings_containing_key_terms` (`R:876`, applied at `R:905-949`).
- **Normalisation:** see SCHEMA §4. Text keeps HTML entities and attributes are entity-decoded [code] (`R:932`, `R:1061`).
- **Insert** [code]. New originals: `insert_strings()` (`Q:1022-1056`) → `original_strings_sync()` (`Q:711-758`) for ids → one multi-row `INSERT` with `status 0`. The whole insert is gated by the filter `trp_allow_string_saving` (`R:2268`). This means **every page view in a translation language can add rows** (the "pro-active scan" described in `TP/includes/advanced-settings/manual-translation-only.php:12`).
- **Post context:** see SCHEMA §8 [code] (`R:2797-2819`, `R:1451-1458`, `Q:760-813`).

## 4. Gettext strings (background, not v1)

[code]
- TP hooks the `gettext` family (`TP/includes/gettext/class-gettext-manager.php:169`).
- It wraps each translated string in text markers: `#!trpst#trp-gettext data-trpgettextoriginal=ID#!trpen#…#!trpst#/trp-gettext#!trpen#` (`TP/includes/gettext/class-process-gettext.php:535`).
- `translate_page()` later turns the markers into nodes or strips them (`R:1610-1670`, `R:1741-1800`).
- Gettext machine translation runs separately (`class-gettext-manager.php:587`). It shares the request's time budget, with regular strings served first (comment at `R:2154-2158`).

[inferred] The markers can leak into contexts TP doesn't render (emails, JSON, date formats). Several strip helpers exist for that reason (`class-gettext-manager.php:634-641`, `:730-760`).

## 5. How automatic translation is triggered

[code]
- **Only inside `process_strings()`** (`R:2041-2273`).
- **Callers:**
  - front-end render (`R:1105`);
  - the editor's `get_translations` (`TP/includes/class-editor-api-regular-strings.php:220`, `:241`);
  - String Translation's `get_strings` (`TP/includes/string-translation/class-string-translation-api-regular.php:56`).
- **Conditions:**
  - `is_available()`: setting enabled; the DeepL engine needs a valid license; the language pair is supported (`TP/includes/class-machine-translator.php:86-111`).
  - Not `manual_translation_only` (`TP/includes/advanced-settings/manual-translation-only.php:38-47`).
  - `verify_request()`: API key present, not a crawler when `block-crawlers = yes`, daily quota not exceeded (`class-machine-translator.php:286-308`).
  - `should_translate_string()` filters out very short or code-like strings (`:369-570`).
- **Mechanics:**
  - Strings are de-duplicated and sent in chunks of `get_chunk_size()` (`R:2167-2169`).
  - A request-wide time budget applies: `trp_machine_translation_time_budget`, default 10 s (`R:2158-2172`).
  - Each chunk is saved immediately with `status 1` (`R:2184-2207`).
  - Concurrent requests are serialised with a **lock table**: `claim_machine_translation_locks()` etc. (`Q:339-544`, `class-machine-translator.php:694-822`), cleaned hourly by cron `trp_machine_translation_lock_cleanup` (`:60-71`).
  - `%s`, `%d`, `%`, `$` and `#` are swapped for placeholders before sending (filter `trp_exclude_words_from_automatic_translation`, `:580`). Quotes and punctuation are restored afterwards (`:900`, `:1016`).
- **Engines:** `trp_automatic_translation_engines_classes` maps `mtapi` → `TRP_MTAPI_Machine_Translator`, `google_translate_v2`, `deepl` (`TP/includes/class-machine-translation-tab.php:188-212`).
- **Side effects on reads:** `check_languages_availability()` can call the engine and `update_option( 'trp_db_stored_data' )` (`class-machine-translator.php:113-147`).

## 6. Tables

[code] Full column lists are in SCHEMA §1. All names are built from `$wpdb->prefix`.

| Table | Purpose | Reference |
|---|---|---|
| `trp_dictionary_{def}_{lang}` | regular strings per language | `Q:246-288`, `Q:1472-1482` |
| `trp_original_strings` | one row per distinct original (not enforced unique) | `Q:572-590` |
| `trp_original_meta` | `post_parent_id` meta | `Q:815-845` |
| `trp_gettext_{lang}`, `trp_gettext_original_strings`, `trp_gettext_original_meta` | gettext | `TP/includes/queries/class-gettext-table-creation.php:47-138` |
| `trp_gettext_originals_dedup_map`, `trp_gettext_locale_dedup_map` | gettext migration maps | `Q:1209`, `Q:1626-1642` |
| `trp_machine_translation_log` | machine-translation log | `Q:298-332` |
| machine-translation lock table | concurrency locks | `Q:339-375` |
| `trp_slug_translations` | slugs | named in `TP/includes/upgrade/tasks/class-failed-translations-cleanup.php` |

## 7. Options

[code]
- `trp_settings`: languages and URL structure (`TP/includes/class-settings.php:248`, `:401`).
- `trp_machine_translation_settings`: automatic translation. Keys and secrets are in SCHEMA §10; defaults at `class-settings.php:493-512`.
- `trp_advanced_settings`: includes `manual_translation_only`, `enable_numerals_translation` (used by `trp_full_trim`, `TP/includes/external-functions.php:21-32`) and the post-container toggles.
- `trp_license_key` / `trp_license_status` / `trp_license_details`: **secret or sensitive** (`TP/includes/mtapi/class-mtapi-machine-translator.php:187`).
- `trp_db_stored_data`: cached engine-supported languages (`class-machine-translator.php:121-139`).
- `trp_plugin_version`: the database version, compared on upgrade (`TP/includes/class-upgrade.php:55`, `:159`).
- `trp_machine_translated_characters`, `trp_db_errors`, and the `trp_updated_database_*` / `trp_batch_*` progress flags of upgrade jobs (option names found by grep over `get_option( 'trp_…`).

## 8. Hooks and filters relevant to string translation

All [code]. Actions are marked "(action)"; the rest are filters.

| Hook | Where | What it does |
|---|---|---|
| `trp_translating_capability` | e.g. `TP/includes/class-editor-api-regular-strings.php:41`, `:289` | capability for the editor and String Translation, default `manage_options` |
| `trp_save_editor_translations_regular_strings` (action) | `class-editor-api-regular-strings.php:354` | after an editor save; no listeners in free TP |
| `trp_save_editor_translations_gettext_strings` (action) | `TP/includes/class-editor-api-gettext-strings.php:120` | gettext counterpart |
| `trp_allow_string_saving` | `R:2200`, `R:2268` | veto inserting or saving rows during render; used by `manual_translation_only` |
| `trp_get_existing_translations` | `Q:158` | rewrite the fetched dictionary; used by serve-similar (`TP/includes/advanced-settings/serve-similar-translation.php:36`) and a host query-limit check (`TP/includes/compatibility-functions.php:2172`) |
| `trp_machine_translator_is_available` | `TP/includes/class-machine-translator.php:110` | final say on machine translation |
| `trp_allow_machine_translation_for_string` / `…_for_url` | `R:920`, `R:981`, `R:985`, `R:1077` | skip machine translation per string |
| `trp_machine_translation_time_budget` | `R:2161` | seconds per request |
| `trp_exclude_words_from_automatic_translation` | `class-machine-translator.php:580` | tokens protected from the engine |
| `trp_add_similar_and_original_strings_to_db` | `R:2087`, `R:2135` | enables status-3 rows |
| `trp_enable_translation_blocks_querying` | `Q:70` | disable translation-block lookups |
| `trp_table_name_dictionary` / `trp_table_name_original_strings` / `trp_table_name_original_meta` / `trp_table_name_gettext` | `Q:1481`, `Q:1160`, `Q:1169`, `Q:1489` | table names are filterable |
| `trp_node_accessors` | `R:2325` | which attributes are translatable; the SEO Pack adds meta tags here |
| `trp_translateable_strings` / `trp_translateable_strings_manual` | `R:1086`, `R:1111` | edit the collected string list |
| `trp_translateable_information` (action) | `R:1141` | all strings and translations of a rendered page; the only per-render usage signal |
| `trp_stop_translating_page`, `trp_before_translate_content`, `trp_pre_translating_html`, `trp_translated_html` | `R:559`, `R:571`, `R:675`, `R:1275` | whole-page hooks |
| `trp_media_url_node_types` | `R:1012` | node types that are never inserted outside preview |

Verified **not** to exist: `trp_translate_string` and `trp_get_translation` (SCHEMA §6).

## 9. Caching

[code]
- The only regular-string object-cache entry is `get_all_translation_blocks_{md5}`, in group `trp` (`Q:71-77`). The group is non-persistent (`TP/class-translate-press.php:87`).
- Other `trp`-group entries are URL helpers (`TP/includes/class-url-converter.php:423-923`).
- Transients:
  - `processed_original_string_meta_post_id_for_{md4(permalink)}`, 24 h (`R:1163`, `R:1173`);
  - WooCommerce slug transients (`class-url-converter.php:589-606`);
  - `trp_mtapi_cached_quota` (`TP/includes/class-ai-words-notification.php:173`).
- `trp_get_transient()` / the cache wrapper bypass caching when `TRP_DEBUG` is on (`TP/includes/functions.php:772-797`).
- Nothing in TP invalidates anything after a translation save. Full-page caches are not purged (SCHEMA §7).

## 10. Gotchas and fragile behaviours

1. **Rendering creates data.** Every view of a translated page can insert rows and spend machine-translation quota. So can opening the editor or String Translation [code] (`R:2268`, `class-editor-api-regular-strings.php:220`, `class-string-translation-api-regular.php:56`). Never call these paths from read code.
2. **Text-based matching.** Render lookups use `original IN (…)` with the column collation, usually case-insensitive. `trp_original_strings` lookups use `BINARY` (`Q:105`, `Q:1089-1120`). [inferred] Case variants can collide in dictionary lookups but not in the originals table. The code handles "2 results for the same original" (`Q:118-138`).
3. **Duplicates are normal.** There is no UNIQUE key on `original` in either table (`Q:252-260`, `Q:579-581`). The editor deletes duplicates after each save (`Q:2049-2085`), and there is a dedicated repair tool (`TP/includes/class-upgrade.php:1021`).
4. **`original_id` can be stale or null** until the reindex job runs. That job re-points rows by binary text (`Q:642-709`, UPDATE at `Q:691`).
5. **Empty translation with `status != 0` is "sticky".** It is never re-translated (`R:2096-2104`). TP itself writes this state when machine output was stripped of disallowed HTML (comment there).
6. **`trp_sanitize_string()` strips every `%xx` hex octet** (`TP/includes/functions.php:213-221`). [inferred] Legitimate text like `50%ab` or `100%DE` is mangled when saved through TP paths.
7. **The editor's `update_strings()` is an upsert** keyed by `id` with partial columns (`Q:955-1013`). [inferred] An unknown `id` could insert a row without `original`; TP never hits that case because its ids come from a fresh read.
8. **Several queries interpolate values instead of preparing them.** Examples: `set_original_string_meta_post_id()` uses `'%2s'` with `implode` (`Q:784-786`), and the String Translation search builds SQL with `esc_sql` (`TP/includes/string-translation/class-string-translation-helper.php:270-330`, e.g. `:296-301`). [inferred] Don't copy these patterns; `$wpdb->prepare` everything (CLAUDE.md rule 3).
9. **Invalid language codes return a sentinel table name**, `trp_language_code_is_invalid_error` (`Q:1476-1479`). Always validate against the settings list first.
10. **The settings array is a snapshot.** Components keep the array they were built with (`TP/class-translate-press.php:181-199`). [inferred] Options changed during a request are not seen by TP until the next request.
11. **Translation-block originals contain HTML** and are matched in a stripped, decoded, whitespace-collapsed form (`R:292-294`). Editing them through text matching is unreliable.
12. **`SHOW TABLES LIKE` wildcards.** `_` matched sibling tables until TP added `trp_table_exists_exact()` (`Q:2035-2037`). `get_all_table_names()` still uses an unescaped `LIKE` (`Q:1611-1624`). [inferred] It can include tables of deactivated languages and similarly named tables.
13. **Abilities API, permission errors.** A `WP_Error` from `permission_callback` is replaced by the generic `ability_invalid_permissions` (`WP/abilities-api/class-wp-ability.php:605-621`). Return specific errors from `execute_callback`.
14. **Abilities API, nested defaults.** Only the top-level `default` is applied, and only for `null` input (`WP/abilities-api/class-wp-ability.php:442-454`). Nested defaults are never filled.
15. **Abilities API, GET input on 6.9.** It arrives uncoerced, so numbers and booleans are strings (`WP/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php:193-203`). 7.1 coerces (7.1 `:241-331`).
16. **Abilities API, destructive + idempotent → HTTP DELETE.** Query-string input is not JSON-decoded, so TP deliberately avoids this combination (`WP/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php:110-133`, `TP/includes/class-abilities.php:259-265`).
17. **Abilities API, callbacks without an input schema get no argument at all** (`WP/abilities-api/class-wp-ability.php:505-512`).
18. **Engine language codes need an HTTP-capable lookup.** Matching a language against `trp_db_stored_data['trp_mt_supported_languages'][engine]['languages']` goes through each engine's `get_engine_specific_language_codes()` (e.g. `TP/includes/mtapi/class-mtapi-machine-translator.php:221-231`, filter `trp_mtapi_source_language`). That calls `TRP_Languages::get_iso_codes()` (`TP/includes/class-languages.php:177`, filter `trp_map_wp_codes_to_google`), which calls `get_wp_languages()` → `wp_get_available_translations()` (`:154-165`) [code]. [inferred] On a cold `available_translations` site transient this is an HTTP call to wordpress.org. This is why `get-auto-translation-settings` returns the languages unfiltered (owner decision, Stage 1).
19. **Abilities API, empty objects over REST.** The run controller returns the execute result through `rest_ensure_response()` unchanged (`WP/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php:93-98`), so an empty `stdClass` (e.g. `get-strings` `translations` with no row) is sent as `{}` [code]. The list controller even turns an empty `default` of an object schema into `{}` on purpose (`WP/rest-api/endpoints/class-wp-rest-abilities-v1-list-controller.php:208-215`, applied at `:233`) [code]. A hosting connector showed that same `default` as `[]` (Stage 2): it re-encodes JSON objects as arrays, so `[]` seen through it may be `{}` on the wire [inferred from that observation].
20. **MySQL 8 and empty dates.** A `DATETIME` column compared with `''` (e.g. `created_at >= ''`) failed the whole query on the dev site, even inside `('' = '' OR …)`; `$wpdb` then returns `null` and the error is easy to miss [inferred from the dev-site symptom: `get-change-log` empty while `revert` read the same table]. Add a condition only when its filter is set.

## 10a. Which duplicate row TranslatePress renders (decision A)

Read in `Q:94-170` (`get_existing_translations()`) and `R:2062`, `R:2096-2113` (`process_strings()`) at Stage 1. All [code] unless marked.

- The render query is `SELECT original, translated, status … WHERE status != 0 AND original IN (…)` with **no `ORDER BY`** and, from the front end, **no `block_type` filter** (`Q:105`; callers at `R:1115`, `R:2062` pass no `$block_type`).
- Results are keyed by the PHP string `$row->original` (`Q:123`). The page looks up `$dictionary[ $string ]` with the exact page text (`R:2111-2112`). [inferred] So only rows with byte-identical `original` compete. Case variants are returned by the collation but land under different keys.
- Among competing rows, **the first row returned wins**. It is replaced only when it has an `empty()` translation and a later row has a non-empty one (`Q:125-139`). `empty()` is PHP's, so `''`, `NULL` and `'0'` all count as empty.
- If the chosen row's translation is empty, nothing is shown and the string is not re-machine-translated (`R:2096-2104`).
- Rows with `status = 0` are never rendered. When every row is untranslated, machine translation fills the row returned first by `get_untranslated_strings()` (`Q:1127-1144`), because `OBJECT_K` keeps the first row per key (`WP/class-wpdb.php:3165-3176`).
- [inferred] With no `ORDER BY`, "first" is MySQL's choice. For an InnoDB lookup through `index_name (original(100))` it is normally ascending `id`, but this is not guaranteed.

**Rule the plugin applies** for one `original_id` in one language (rows with `block_type IN (0, 1)`, ordered by `id` ascending):
1. the first row with `status != 0` and a non-empty translation (PHP `empty()` semantics);
2. otherwise the first row with `status != 0`;
3. otherwise the first row (all untranslated).

Any other row for that `original_id` is reported with `sat_duplicate_rows`. Known gap [inferred]: render does not filter `block_type`, so a `block_type = 2` row with byte-identical text could be the rendered one. Decision C excludes those rows, and a deprecated block's HTML original rarely equals a plain string.

## 10b. String Translation counts vs `get-stats`

Read at Stage 2 in `get_originals_results()` (`H` = `TP/includes/string-translation/class-string-translation-helper.php`), called for regular strings from `TP/includes/string-translation/class-string-translation-api-regular.php:26-32` with filters `status` and `block_type`. All [code] unless marked.

- **The count is `SELECT COUNT(*)`, not `COUNT(DISTINCT …)`** (`H:240`, run at `H:350`, `H:366`). The listed page uses `SELECT DISTINCT original_strings.id` (`H:241`), so the count can exceed what the list shows.
- **"All statuses" is no status filter.** Ticking every status box empties the status list (`H:59-62`). Without a status or block-type filter and without a search, no dictionary table is joined at all (`H:245-254`). The count is then every row of `trp_original_strings` (`H:243`), **the same number for every language**. It includes:
  - originals with no row in that language (seen only in other languages, or before the language was added). No status filter can match them: `LEFT JOIN … WHERE lang.status = N` drops rows with no match;
  - originals whose only rows in that language have `block_type 2`, `original_id` mismatches, or stale links;
  - duplicate originals (no UNIQUE key, `Q:579-581`).
- **Per-status counts include duplicate rows.** The status filter joins `{dict} AS lang ON lang.original_id = original_strings.id` (`H:144-146`) and adds `lang.status = N` (`H:113-142`). `COUNT(*)` then counts one per **matching dictionary row**: an original with two rows of the same status counts twice, and one with rows of different statuses counts once in each filter.
- **No `block_type` filter** unless the separate translation-block filter is set (`H:216-222`, `H:252-254`), so `block_type 2` rows are counted.
- **Status 3 (similar) is in no filter.** The regular filters are `human_reviewed` (2), `machine_translated` (1) and `not_translated` (0) (`TP/includes/string-translation/class-string-translation.php:173-184`, mapped through `get_constant_*` at `H:53-57`; the gettext-only filter is removed at `H:49-51`). `get-stats` counts status 3 as `machine` (decision B).
- **Viewing String Translation inserts rows.** For originals on the listed page that have no row in a language, it calls `process_strings()` (`class-string-translation-api-regular.php:44-56`), which inserts them and may machine-translate them (§10 item 1). Counts can therefore change just by browsing it.

**Why TranslatePress is higher than `get-stats` per status:** `get-stats` counts one row per `original_id` (the rendered one, §10a) and only `block_type IN (0, 1)`. TranslatePress counts every duplicate row and `block_type 2` rows. Two effects go the other way, so the gap is the net: TranslatePress leaves out status 3, and its join leaves out rows whose `original_id` is `NULL`, `0` or not in `trp_original_strings`, which `get-stats` counts [inferred from `Strings::stats()`, whose first query has no `original_id` condition].

**Why "all statuses" exceeds the sum of the three filters:** mainly originals with no row in that language, plus originals with only `block_type 2` rows, minus the duplicates and status-3 rows above.

[inferred] To confirm on a site, per language: `SELECT COUNT(*) FROM {originals}` (TranslatePress "all"), `SELECT status, COUNT(*) FROM {originals} o JOIN {dict} d ON d.original_id = o.id GROUP BY status` (TranslatePress per status), against `get-stats`.

## 11. What is likely to break on a TranslatePress update

All items here are [inferred] from how the code is shaped. Re-check them on every TranslatePress version bump.

- **Dictionary columns.** TP already evolved them with `ALTER TABLE` (`block_type`, `original_id`, `Q:849-901`). A new column is harmless; a rename or type change breaks reads. The schema guard (REQUIREMENTS §7.5) covers this.
- **Status constants** (`Q:34-41`). Values 3 and 4 were added over time; a new status would be miscounted.
- **Table naming and filters** (`Q:1472-1482`). Gettext already moved to new tables with migrations (`Q:1177-1300`, `TP/includes/upgrade/gettext-optimization/`). Regular strings could follow.
- **`original_strings` linking.** It is currently rebuilt by batch jobs (`Q:594-709`). A move to a hash column, as gettext did with `lookup_hash`, would change exact-match lookup.
- **Sanitisation and normalisation** (`trp_full_trim`, `trp_sanitize_string`, entity handling). Any change alters what "exact original" means and breaks `originals[]` lookups for strings stored afterwards.
- **Editor save path.** `save_translations_of_strings()` is protected and has changed shape before (comments about URL-safe sanitisation, `TP/includes/class-editor-api-regular-strings.php:323`). We don't call it, but its side effects (duplicate removal, the action) may grow.
- **Machine-translation settings keys** (`TP/includes/class-machine-translation-tab.php:78`) and engine slugs (`:196-200`). New keys could be secrets, which is why an allowlist is used, never a denylist.
- **TP's own abilities** (`TP/includes/class-abilities.php`). TP may add string or automatic-translation-state abilities that overlap ours. Check before each release.
- **Core Abilities API** is young: 6.9 → 7.1 already added `meta.public`, new filters and input coercion (diff of `class-wp-ability.php` and the run controller). Keep to the 6.9 contract.
