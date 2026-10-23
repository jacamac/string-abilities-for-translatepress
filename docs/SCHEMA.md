# SCHEMA — Stage 0 discovery

Answers to `docs/REQUIREMENTS.md` §4, verified against source code. Every claim cites the file and line it was read from. Nothing here comes from memory.

## Sources examined

| Source | Version | Obtained from | SHA-256 of archive |
|---|---|---|---|
| TranslatePress – Multilingual (free) | 3.3.8 | `downloads.wordpress.org/plugin/translatepress-multilingual.3.3.8.zip` | `aa78ce60c6cf244c121f4f13ae4984282b2795f17902dff17ae45f6a0bbbcf05` |
| WordPress core | 6.9 (minimum supported) | `wordpress.org/wordpress-6.9.zip` | `3e95777a3acb3b05d5cdbd31b16fc1e9b71122ebe3c1f18c6f53dee3eab0d111` |
| WordPress core | 7.1.3 (latest on 2026-10-23) | `wordpress.org/latest.zip` | `eb58a90ed55147f90f7bd55d0d3334960a69b7e8a4cc142c8ddfea8965218227` |
| MCP Adapter | 0.7.1 (GitHub `trunk`) | `github.com/WordPress/mcp-adapter` | — (only read for `meta.mcp.public`) |

Path conventions below: `TP/` = `translatepress-multilingual/`, `WP/` = `wordpress/wp-includes/` (6.9 unless stated "7.1"). The archives were unpacked into the git-ignored `reference/` directory.

Constants used throughout: `TRP_Query::NOT_TRANSLATED = 0`, `MACHINE_TRANSLATED = 1`, `HUMAN_REVIEWED = 2`, `SIMILAR_TRANSLATED = 3`, `GETTEXT_TRANSLATED_IN_LANGUAGE_FILE = 4`, `BLOCK_TYPE_REGULAR_STRING = 0`, `BLOCK_TYPE_ACTIVE = 1`, `BLOCK_TYPE_DEPRECATED = 2` (`TP/includes/queries/class-query.php:34-41`).

The TranslatePress query component is reached with `TRP_Translate_Press::get_trp_instance()->get_component( 'query' )` (`TP/class-translate-press.php:64`, `:104`).

---

## 1. Tables and columns for regular strings

The [VERIFY] expectation is **confirmed**.

**Dictionary, one table per translation language:** `{prefix}trp_dictionary_{default}_{lang}`, both codes lower-cased, e.g. `wp_trp_dictionary_en_gb_fr_fr`. Built by `TRP_Query::get_table_name()` and filterable with `trp_table_name_dictionary` (`TP/includes/queries/class-query.php:1472-1482`). If either code fails `trp_is_valid_language_code()` (only `A-Za-z0-9-_` allowed, `TP/includes/external-functions.php:146-152`), the method returns the literal string `trp_language_code_is_invalid_error` instead of a table name. The default language has no dictionary table.

Created in `check_table()` (`class-query.php:246-288`):

| Column | Type |
|---|---|
| `id` | `bigint(20) AUTO_INCREMENT PRIMARY KEY` |
| `original` | `longtext NOT NULL` |
| `translated` | `longtext` (nullable) |
| `status` | `int(20) DEFAULT 0` |
| `block_type` | `int(20) DEFAULT 0` |
| `original_id` | `bigint(20) DEFAULT NULL` |

Indexes: `index_name (original(100))`, `block_type`, FULLTEXT `original_fulltext (original)`. On older tables `block_type` and `original_id` are added later by `ALTER TABLE` (`class-query.php:849-901`).

**Originals:** `{prefix}trp_original_strings` (filter `trp_table_name_original_strings`, `class-query.php:1159-1161`). Columns: `id bigint AUTO_INCREMENT PRIMARY KEY` and `original TEXT NOT NULL`, with index `index_original (original(100))` (`class-query.php:572-590`). There is **no UNIQUE key on `original`**.

**Original meta:** `{prefix}trp_original_meta` (filter `trp_table_name_original_meta`, `class-query.php:1168-1170`). Columns: `meta_id` PK, `original_id bigint NOT NULL`, `meta_key varchar(255)`, `meta_value longtext`. Indexed on `original_id` and `meta_key` (`class-query.php:815-845`).

**How a dictionary row links to `original_id`:** `dictionary.original_id = trp_original_strings.id`.
- When strings are inserted, `insert_strings()` first calls `original_strings_sync()`. That function looks up each original with a `CAST(... AS BINARY)` comparison, inserts any missing ones, and writes the resulting id into the dictionary row (`class-query.php:711-758`, `:1022-1056`).
- The batch upgrade `original_ids_reindex()` re-links rows by **binary** equality of `original` text. It also re-points any row whose current `original_id` target text no longer matches (`class-query.php:642-709`).
- The String Translation admin page joins the tables exactly this way: `LEFT JOIN dict AS {lang} ON {lang}.original_id = original_strings.id` (`TP/includes/string-translation/class-string-translation-helper.php:144-146`).

The same `original_id` is shared by every language.

Caveats seen in the code:
- **Duplicate dictionary rows for one original can exist.**
  - `get_existing_translations()` handles "2 results for the same original" (`class-query.php:118-138`).
  - The editor deletes duplicates after each save with `remove_possible_duplicates()` (`TP/includes/class-editor-api-regular-strings.php:351`, `class-query.php:2049-2085`).
  - There is a "Remove duplicate rows" admin tool (`TP/includes/class-upgrade.php:42`, `:1021`).
- `original_id` may be `NULL`/`0` on rows that have not been re-indexed yet.
- **Collation:** dictionary lookups by text use plain `original IN (...)` (`class-query.php:105`, `:1509-1518`), so they follow the column collation, which is usually case-insensitive. Lookups on `trp_original_strings` use `BINARY` (`class-query.php:1089-1120`).

**Impact on v1:**
- Address rows by `original_id` + language. Build table names only through `TRP_Query::get_table_name()` (this respects TP's filter), and only for codes found in `trp_settings['translation-languages']` that are not the default language.
- Exact-text lookup goes against `trp_original_strings` with `BINARY`, then joins the dictionaries on `original_id`.
- Schema guard: check the six dictionary columns plus `trp_original_strings.(id, original)`.
- Open question A (duplicates) and open question C (`block_type` scope) are listed at the end.

## 2. Status values

The [VERIFY] expectation is **confirmed and extended** (`class-query.php:34-38`):

| Value | Meaning | Where it comes from |
|---|---|---|
| 0 | not translated | `insert_strings()` inserts `translated = NULL` (written as `''` through `prepare`), `status = 0` (`class-query.php:1040`) |
| 1 | machine translated | machine-translation chunk save (`TP/includes/class-translation-render.php:2169-2207`) |
| 2 | human reviewed | the editor JS sends `status = (editedTranslation === "" ? 0 : 2)` (`TP/assets/js/trp-editor.js`, line 2, minified); the server casts it as-is (`class-editor-api-regular-strings.php:335`) |
| 3 | similar translated | only when the free advanced setting "serve similar translations" is on (`class-translation-render.php:2246-2257`, `TP/includes/advanced-settings/serve-similar-translation.php`) |
| 4 | gettext only (translated in a language file) | not used in dictionary tables |

Rendering serves any row with `status != 0` (`class-query.php:105`). A row with `status != 0` and an empty `translated` is deliberately **never** re-machine-translated (`class-translation-render.php:2096-2104`). The editor has no explicit "approve" action: saving unchanged text is a no-op on the client.

**Impact on v1:**
- Map 0 → `untranslated`, 1 → `machine`, 2 → `human`.
- `reset` must write `status = 0` and `translated = ''`, the same as the editor's clear action. Writing `status = 1/2` with empty text would freeze the string untranslated.
- Treat a `NULL` translation as `''` when comparing `expected_current`.
- Open question B (status 3).

## 3. Meaning of `block_type`

- `0` = regular string (one text node or attribute).
- `1` = **active translation block**: the user merged a whole HTML fragment into one translatable unit. `original` then contains inner HTML.
- `2` = **deprecated translation block**: the block was split again. The row is kept but no longer matched as a block.

References: `class-query.php:39-41`. Blocks are created in `create_translation_block()` (`class-editor-api-regular-strings.php:365-457`), which writes `block_type = 1` into every language table, including deactivated ones. They are split in `split_translation_block()` (`:466-495`, sets 2). Active and deprecated blocks are fetched for rendering by `get_all_translation_blocks()` (`class-query.php:69-83`). Rendering matches them on `trim_translation_block()`, a stripped, entity-decoded and whitespace-collapsed form (`class-translation-render.php:292-294`, `:704-720`). Block rows are translated like any other dictionary row (`.translation-block` innertext, `class-translation-render.php:903-927`).

**Impact on v1:** recommend `block_type IN (0, 1)` for reads and writes, and never changing `block_type`. Rows with `2` are leftovers and are excluded. Because block originals contain HTML, `Validator`'s HTML checks matter for them. Open question C.

## 4. How originals are normalised when stored

Front-end extraction (`TranslatePress\str_get_html` / simple_html_dom), in `translate_page()`:
- **Text nodes** (`trptext`): `trp_full_trim( $row->outertext )` (`class-translation-render.php:930-932`). This is the raw HTML source text, **not entity-decoded**: `&amp;`, `&#8217;` etc. stay exactly as emitted.
- **Translation blocks:** `trp_full_trim( $row->innertext )` (`:904`).
- **Attributes** (title, placeholder, alt, aria-label, etc.): `trp_full_trim()` and then **`html_entity_decode()`** (`:1022`, `:1061`).

`trp_full_trim()` (`TP/includes/external-functions.php:15-73`):
- It repeatedly trims ASCII whitespace (`" \t\n\r\0\x0B"`) and leading or trailing `\xc2\xa0` / `&nbsp;`.
- **Internal whitespace and newlines are preserved.**
- It returns `''` for strings that are only punctuation or symbols, and also only digits unless numeral translation is enabled. Such strings are never stored.

Strings that are numeric, match `^\d+%$`, sit inside `<title>`, `script`, `style`, `textarea` or `data-no-translation`, or are CDATA are skipped (`class-translation-render.php:905-916`, `:933-949`).

Editor path: `trp_full_trim( trp_sanitize_string( $original, false ) )` (`class-editor-api-regular-strings.php:201`). `trp_sanitize_string()` (`TP/includes/functions.php:202-227`) does four things:
- removes `<script>…</script>`,
- trims,
- **removes every `%xx` hex octet**, i.e. `/%[a-f0-9]{2}/i`,
- runs `wp_kses_post` when `$execute_wp_kses` is true. That is the case for translations, not originals.

When stored, the original is written as received, with no further change (`insert_strings()`, `original_strings_sync()`). Originals over 20 000 bytes are not copied into `trp_original_strings` by the upgrade (`class-query.php:611`).

**Impact on v1:**
- `get-strings` `originals[]` applies `trp_full_trim()` to each input and then matches `BINARY` on `trp_original_strings.original`.
- The ability description must say that originals are stored as they appear in the page HTML: text keeps entities (`&amp;`), attributes are decoded. That makes text copied from a rendered page or from the block editor a likely `not_found`.
- No fuzzy or variant matching (minimum code; `find-similar` is already in LATER).

## 5. How the editor saves a translation; can it be called directly?

Path: AJAX `wp_ajax_trp_save_translations_regular` → `TRP_Editor_Api_Regular_Strings::save_translations()` (`TP/class-translate-press.php:367`, `class-editor-api-regular-strings.php:288-298`) → `save_translations_of_strings()` (`:306-357`). In order, it:

1. keeps only languages in `translation-languages` that are not the default (`:316`);
2. sanitises: `esc_url_raw()` if the translation is a URL, otherwise `trp_sanitize_string()`, i.e. strip script, strip `%xx`, `wp_kses_post` (`:323-329`);
3. `TRP_Query::update_strings( $rows, $lang, array( 'id', 'translated', 'status', 'block_type' ) )` (`:350`). This is an `INSERT … ON DUPLICATE KEY UPDATE` keyed on `id` (`class-query.php:955-1013`);
4. `remove_possible_duplicates()`: a **DELETE** of every other row with the same binary `original` in that language (`:351`, `class-query.php:2049-2085`);
5. `do_action( 'trp_save_editor_translations_regular_strings', $update_strings, $settings )` (`:354`).

**Can it be called directly? No.**
- `save_translations_of_strings()` is `protected`.
- The public `save_translations()` requires `DOING_AJAX`, a `save_translations` nonce, `$_POST` input, and `current_user_can( apply_filters( 'trp_translating_capability', 'manage_options' ) )`. It then `die()`s.

`TRP_Query::update_strings()` is public and callable. However:
- it is an upsert, so an unknown `id` inserts a new row;
- it has no compare-and-swap;
- it would need step 4 to mirror the editor, and step 4 deletes rows, which CLAUDE.md rule 4 forbids.

**Impact on v1:** use the fallback, `$wpdb`, and replicate the side effects as follows.

- Write with a plain conditional `UPDATE` that can never insert:
  `UPDATE {dict} SET translated = %s, status = %d WHERE id = %d AND original_id = %d AND BINARY COALESCE(translated,'') = %s AND status = %d`.
  If 0 rows are affected, the outcome is `conflict`. This makes `expected_current` atomic.
- Run the sanitisation from step 2: pass the final text through `trp_sanitize_string()` after `Validator` and `wp_kses`, so what is stored matches what the editor would store. If sanitisation changes the text, treat the item as rejected rather than storing something different from what was validated. The `%xx` stripping can alter text such as `50%ab`.
- **Skip step 4** (deletes).
- Fire the action from step 5 with the same argument shape? Open question D.
- There is no cache step to replicate (§7).

## 6. Hooks fired on save

- **On an editor save:** only `trp_save_editor_translations_regular_strings` (`class-editor-api-regular-strings.php:354`). **Nothing in free TP 3.3.8 listens to it** (searched the whole plugin).
- **Gettext counterpart:** `trp_save_editor_translations_gettext_strings` (`TP/includes/class-editor-api-gettext-strings.php:120`).
- **Machine-translation and front-end insert paths:** they fire the filter `trp_allow_string_saving` (`class-translation-render.php:2200`, `:2268`) and no action.
- **Read-side filter:** `trp_get_existing_translations` (`class-query.php:158`).
- **`trp_translate_string` and `trp_get_translation` do not exist** anywhere in TP 3.3.8. The unverified claim is false.

**Impact on v1:** we do not depend on any TP hook. Whether to fire `trp_save_editor_translations_regular_strings` is open question D.

## 7. Translation caching and invalidation

- Regular-string translations are read from the database on every render: `get_existing_translations()` has no cache (`class-query.php:94-170`).
- The only object-cache use on this path is `get_all_translation_blocks()`: key `get_all_translation_blocks_{md5(lang)}`, group `trp` (`class-query.php:71-77`).
- The `trp` group is registered **non-persistent** (`wp_cache_add_non_persistent_groups( array( 'trp' ) )`, `TP/class-translate-press.php:87`). It therefore lives for one request only and is never stored in Redis or any other persistent object cache.
- TP does no cache invalidation of any kind after an editor save, and has no page-cache purge integration on save (searched for `purge`, `flush`, `clear_cache`, `rocket_`, `litespeed`).
- Translation happens when the page is rendered, through the output buffer (`translate_page()`). **Full-page caches** (a caching plugin, host or CDN) will therefore keep serving the old HTML until their normal expiry or purge. This is outside TranslatePress.

**Impact on v1:**
- Nothing to invalidate in TP or in the object cache, so no cache code.
- The smoke-test step "front end shows it" must bypass or purge any page cache. README should mention this in one line.

## 8. What `trp_original_meta` records; can context be derived with free TP only?

The only meta key free TP **writes** is `post_parent_id`, whose value is a post ID (`class-query.php:760-813`, `:1301-1303`). Conditions, all from the code:
- The `the_content` and `the_title` filters (priority 1000) wrap output in `<trp-post-container data-trp-post-id='ID'>`. This happens only for `is_singular()`, in the main loop, in a non-default language, and when `$id` matches the current post (`class-translation-render.php:2797-2819`, hooks at `TP/class-translate-press.php:466-467`).
- Either wrapper can be disabled by free advanced settings (`TP/includes/advanced-settings/disable-post-container-tags.php:11`, `:36`).
- Only **text and translation-block nodes** inside a wrapper get the post ID (`maybe_add_post_id_in_node()`, `class-translation-render.php:1451-1458`, called at `:924`, `:990`). Attribute strings never get it.
- It is written **at most once per 24 h per permalink**, through a transient (`class-translation-render.php:1143-1176`). Rows are add-only and many-to-many (a string can have several post IDs). They are removed only by an explicit string delete.
- **Not recorded:** URL, and whether a string is title, content or meta. Title and content use the same key.
- String Translation filters on `meta_key = 'in_email'` (`class-string-translation-helper.php:262-266`), but nothing in free TP writes that key.

SEO meta tags:
- Free TP **excludes** `<title>` text (`class-translation-render.php:909`, `:936`).
- Its built-in node accessors (`get_node_accessors()`, `:2324-2410`) have no `meta[name=description]` / `og:*` selectors.
- The `meta_desc` / `page_title` node types and the `og:image` / `twitter:image` handling are "registered by the SEO Pack add-on" through the `trp_node_accessors` filter (comment at `:1007-1010`).

**SEO meta-tag translation therefore requires a paid add-on.**

**Impact on v1:** with free TP, context is limited to an incomplete, possibly stale list of post IDs. Recommend **omitting `context`** from `get-strings` in v1 and keeping the LATER entry. Alternative: return `context: { post_ids: [...] }`, documented as partial (open question E). Drop the `<title>` / meta-description length idea unless a paid add-on is assumed; it stays in LATER.

## 9. Is a reset (→ untranslated) machine-translated again on the next page view?

**Yes, when automatic translation is available.** In `process_strings()`:
1. `get_existing_translations()` returns only `status != 0` rows (`class-query.php:105`), so a reset row behaves like a new string.
2. It becomes machine-translatable (`class-translation-render.php:2110-2128`). Translated chunks are saved with `status = 1` into the existing `status = 0` row, matched by `get_untranslated_strings()` (`:2169-2207`, `class-query.php:1127-1144`).

It is **not** re-translated in these cases:
- `trp_machine_translation_settings['machine-translation'] !== 'yes'`, or the engine is unavailable: DeepL needs `trp_license_status === 'valid'`; the language pair is unsupported (`TP/includes/class-machine-translator.php:86-111`).
- No API key, the visitor is a crawler with `block-crawlers = yes`, or the daily quota is exceeded (`verify_request()`, `class-machine-translator.php:286-308`).
- The free advanced setting `manual_translation_only` is on. Then machine translation runs only inside the editor (`TP/includes/advanced-settings/manual-translation-only.php:20-47`).
- The per-request time budget ran out (`class-translation-render.php:2158-2172`), in which case it happens on a later view.

Opening a string in the editor or in String Translation also triggers `process_strings()`, and therefore machine translation (`class-editor-api-regular-strings.php:220`, `TP/includes/string-translation/class-string-translation-api-regular.php:56`).

**Impact on v1:**
- The `reset` description must state that, with automatic translation on, a reset string is machine-translated again on the next view. In practice reset means "redo machine translation".
- **Read abilities must never call `process_strings()`, the editor, or String Translation APIs.** These insert rows and spend machine-translation quota. Read with direct `SELECT`s only.

## 10. Automatic-translation settings: storage and secrets

Option **`trp_machine_translation_settings`** (an array, registered at `TP/includes/class-machine-translation-tab.php:51`). It is merged with defaults when settings load (`TP/includes/class-settings.php:458-470`; defaults at `:493-512`). The keys TP saves are listed in the allowlist at `class-machine-translation-tab.php:78`:

| Key | Meaning | Return? |
|---|---|---|
| `machine-translation` | `'yes'` / `'no'`, enabled | **yes**, as `enabled` |
| `translation-engine` | `mtapi` (TranslatePress AI, default), `google_translate_v2`, `deepl` (`class-machine-translation-tab.php:188-212`) | **yes**, as `engine` |
| `google-translate-key` | Google API key | **never** |
| `deepl-api-key`, `deepl-api-type` | DeepL key and plan | **never** |
| `block-crawlers`, `automatically-translate-slug`, `machine_translation_limit`, `machine_translation_limit_enabled`, `machine_translation_log`, `machine_translation_counter_date` | behaviour and quota | no (not in §5) |
| `ai_words_notification_enabled` / `_email` / `_threshold` | notification settings; **the email is personal data** | **never** |

Related secrets elsewhere, all **never returned**:
- option `trp_license_key`, which is used as the API key by the default `mtapi` engine (`TP/includes/mtapi/class-mtapi-machine-translator.php:185-193`);
- `trp_license_details`;
- `trp_license_status`.

**Languages:** there is **no per-language on/off setting**. Machine translation applies to every `trp_settings['translation-languages']` entry except `default-language` (option `trp_settings`, `class-settings.php:400-401`), as long as the engine supports it. Engine support is cached in option `trp_db_stored_data['trp_mt_supported_languages'][engine]['languages']` (ISO codes, `class-machine-translator.php:113-147`). `check_languages_availability()` and `is_available()` can make **HTTP calls and `update_option()`** (`:126-139`), so they must not be called.

TP's own abilities (`TP/includes/class-abilities.php:65-334`) are `add-language`, `set-license-key`, `enable-automatic-translation`, `list-languages`, `list-available-languages`, `remove-language`, `update-language` and `set-default-language`. **None reads the automatic-translation state.** This confirms §3.

**Impact on v1:**
- `get-auto-translation-settings` reads `get_option( 'trp_machine_translation_settings' )` and `trp_settings` directly and returns only:
  - `enabled` (bool),
  - `engine` (string),
  - `languages`: translation languages minus default. If a cached supported list exists for the engine, it is intersected with that list after mapping through TP's ISO codes; otherwise it is returned unfiltered.
- It uses an explicit allowlist and no TP method calls.
- Open question F: whether to also expose `manual_translation_only`. It changes the meaning of `enabled` but is not in §5.

## 11. Does editing an original break matching?

**Yes.**
- Rendering looks translations up by the **text** of `original` (`class-query.php:105`), so a changed dictionary `original` no longer matches the page string. TP then inserts a new untranslated row, machine-translates it and spends quota, and the edited row is orphaned.
- Editing `trp_original_strings.original` breaks the binary link: `original_ids_reindex()` re-points every dictionary row whose linked original text differs (`class-query.php:691`), and `original_strings_sync()` re-inserts the original text it cannot find (`:711-758`).
- `remove_possible_duplicates()` also keys on binary `original` (`:2049-2085`), so an edited original could make the editor later delete the wrong rows.

**Impact on v1:** this justifies the rule. Writes touch only `translated` and `status`, in a `WHERE id AND original_id` clause, and never `original` or `block_type`.

## 12. Abilities API (WordPress 6.9+)

**Registration:**
- `wp_register_ability( string $name, array $args ): ?WP_Ability` (`WP/abilities-api.php:278`; 7.1: `:290`). Only valid inside the `wp_abilities_api_init` action; otherwise `_doing_it_wrong` and `null` (`:279-291`).
- `wp_register_ability_category( string $slug, array $args ): ?WP_Ability_Category` (`:467`; 7.1: `:645`). Only inside `wp_abilities_api_categories_init` (`:468-480`). `$args` are `label`, `description` (both required) and `meta` (optional).
- Both actions fire when the registry is first used (`WP/abilities-api/class-wp-abilities-registry.php:303`, `class-wp-ability-categories-registry.php:229`). Core hooks its own registration there (`wp-includes/default-filters.php:538-539`).

**Name rules:**
- Ability names must match `/^[a-z0-9-]+\/[a-z0-9-]+$/` (`class-wp-abilities-registry.php:81`).
- Category slugs must match `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` (`class-wp-ability-categories-registry.php:68`).
- The category must be registered before abilities reference it (`class-wp-abilities-registry.php:133-143`).
- `string-abilities/get-stats` and the `string-abilities` category are valid.

**Arguments** (`prepare_properties()`, `WP/abilities-api/class-wp-ability.php:260-338`):
- Required: `label`, `description`, `category` (strings), `execute_callback`, `permission_callback` (callables).
- Optional: `input_schema`, `output_schema` (arrays, JSON Schema) and `meta` (array).
- `meta.annotations` holds `readonly`, `destructive` and `idempotent`, each `bool|null`, default `null` (`:38-51`).
- `meta.show_in_rest` must be a bool and defaults to `false`.
- Unknown `meta` keys, such as `mcp`, are kept untouched.
- 7.1 adds `meta.public` (bool, default false). It seeds `show_in_rest` when that key is absent (7.1 `class-wp-ability.php:37`, `:370-371`).
- Filter `wp_register_ability_args` (`class-wp-abilities-registry.php:129`).

**`meta.mcp.public`** is not a core concept. Core 7.1 only uses it as an example of nested meta filtering (7.1 `abilities-api.php:485`). The MCP Adapter reads it: an explicit `meta.mcp.public` wins, otherwise `meta.public` applies (`mcp-adapter/includes/Abilities/McpAbilityExposure.php:55-68`).

**Execution** (`WP_Ability::execute()`, `class-wp-ability.php:598-655`), in order:
1. `normalize_input()`: only applies the **top-level** schema `default`, and only when the input is `null` (`:442-454`).
2. `validate_input()`: `rest_validate_value_from_schema()`. Failure gives `WP_Error` `ability_invalid_input` (`:463-493`).
3. `check_permissions()`.
4. `wp_before_execute_ability`.
5. The execute callback.
6. `validate_output()`: failure gives `ability_invalid_output` (`:567-588`).
7. `wp_after_execute_ability`.

Callbacks receive `$input` **only if `input_schema` is non-empty** (`invoke_callback()`, `:505-512`).

**Permission callback:**
- It returns `true`, `false` or `WP_Error`.
- Any non-`true` result becomes the generic `ability_invalid_permissions` error, and the original `WP_Error` message is **not passed on** (only `_doing_it_wrong`, `:605-621`).

**Errors:**
- The execute callback returns a `WP_Error`, which is passed back unchanged (`:633-636`).
- Over REST, the HTTP status comes from the error's `data['status']`, defaulting to 500 (`WP/rest-api.php:3416-3423`).

**REST:**
- Route `/wp-abilities/v1/abilities/{name}/run`.
- The HTTP method is derived from annotations: `readonly` → GET; `destructive` and `idempotent` → DELETE; otherwise POST (`WP/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php:110-133`).
- GET and DELETE take `input` from the query string; POST takes it from the JSON body (`:193-203`).
- In 6.9, query-string input is **not type-coerced**: `rest_is_integer` / `rest_is_boolean` accept `"2"` / `"true"`, so validation passes but callbacks receive strings. 7.1 adds coercion (7.1 run controller `:241-331`).
- Validation and permission failures get status 400 / 401-403 (`:161-180`).

**Schema keywords:** `rest_validate_value_from_schema` supports `additionalProperties`, `required` (v4 array form, `WP/rest-api.php:2374`), `enum`, `minLength`/`maxLength`, `minItems`/`maxItems`, `oneOf`/`anyOf` and others (`:2131-2147`).

**7.1 additions** (not usable at 6.9): filters `wp_ability_normalize_input`, `wp_ability_validate_input`, `wp_ability_permission_result`, `wp_ability_execute_result`, `wp_ability_validate_output` (7.1 `class-wp-ability.php:508`, `:566`, `:652`, `:701`, `:748`), and `meta.public`.

**Impact on v1:**
- Register on the two init actions. Set `meta` = `{ show_in_rest: true, mcp: { public: true }, annotations: {…} }`. Read abilities: `readonly: true`, so GET. Writes: `readonly: false`, `destructive: true`, `idempotent: false`, so POST, avoiding DELETE as TP does (`class-abilities.php:259-266`).
- Give every ability an object `input_schema` with `additionalProperties: false`. For no-argument abilities, add a top-level `default` (TP uses `new stdClass()`, `class-abilities.php:144`).
- **Apply property defaults in the callback.** `dry_run` must default to `true` in code, because core never fills nested defaults.
- **Cast input types in the callback**, because of 6.9 GET.
- Permission callbacks only check `sat_read` / `sat_manage`. **`sat_writes_disabled` and `sat_schema_unsupported` must be returned from the execute callback**, because a `WP_Error` from the permission callback is replaced by the generic error.
- Return `WP_Error( 'sat_…', message, array( 'status' => 4xx ) )`. Per-item problems go in the output, not in a `WP_Error`.

## 13. For LATER only

**Gettext storage:** separate tables per language, `{prefix}trp_gettext_{lang}`, with columns `id`, `original`, `translated`, `domain`, `status`, `original_id`, `plural_form` and UNIQUE `(original_id, plural_form)`. Originals live in `{prefix}trp_gettext_original_strings` (`id`, `original`, `domain`, `context`, `original_plural`, `lookup_hash`) and meta in `{prefix}trp_gettext_original_meta` (`TP/includes/queries/class-gettext-table-creation.php:47-138`). Status `4` marks strings translated by a `.mo` file. Keys are `(original, domain, context, plural_form)`, not text alone, and there are dedup map tables and migrations (`class-query.php:1177-1300`). This is a different addressing model from v1's `original_id + language`.

**Orphan detection:** free TP records no "last seen" for regular strings. Rows are inserted on render and never removed automatically. The only usage signal is the add-only, daily-throttled `post_parent_id` meta (§8), which misses attribute strings and non-singular pages. Reliable orphan detection would need the plugin to track usage itself (e.g. by hooking `trp_translateable_information`, `class-translation-render.php:1141`). The LATER entry "Needs reliable usage tracking" stands.

**Built-in cleanup routines:**
- String Translation "delete" (`TRP_Regular_Delete::delete_strings()`): deletes by `original_id` from every translation language's dictionary, from `trp_original_strings` and from `trp_original_meta` (`TP/includes/queries/class-regular-delete.php:31-73`).
- The hidden "Remove duplicate rows" page and its batch jobs (`class-upgrade.php:42`, `:282-296`, `:1021`; `class-query.php:1673-1767`).
- Upgrade jobs removing CDATA and untranslated links (`class-query.php:1858-1933`).
- The failed-translation marker cleanup `__OPENROUTER_FAILED__` (`TP/includes/upgrade/tasks/class-failed-translations-cleanup.php`).
- `clean_original_meta()` removes meta with `original_id = 0` (`class-query.php:2174-2178`).

A future purge could call `TRP_Regular_Delete` after snapshotting rows, but none of this belongs in v1.

---

## Schema guard inputs (§7.5)

- TP version: option `trp_plugin_version` (`class-upgrade.php:55`, `:159`) and constant `TRP_PLUGIN_VERSION` (`class-translate-press.php:85`).
- Expected columns:
  - dictionary: `id`, `original`, `translated`, `status`, `block_type`, `original_id`;
  - `trp_original_strings`: `id`, `original`.
- TP checks columns via `INFORMATION_SCHEMA.COLUMNS` (`class-query.php:909-919`).

## Open questions for the owner

| | Question | Recommendation |
|---|---|---|
| A | Several dictionary rows can share one `original_id` in a language (§1). Which row does a write target? | Read and write the lowest-`id` row with `block_type IN (0,1)` and report the others nowhere. Alternative: reject the item with a new error code (not in §5). |
| B | Status `3` (similar translated, free advanced setting) is not in the spec's three statuses. | Report and count it as `machine`, since it is auto-filled and not human-reviewed. `approve` on it → `human`. |
| C | `block_type` scope. | `0` and `1`; exclude `2`. |
| D | Fire `trp_save_editor_translations_regular_strings` after a real write, with the editor's argument shape? There are no listeners in free TP; paid add-ons are unknown. | Do not fire in v1 (minimum code, no unknown side effects); revisit if an add-on needs it. |
| E | `get-strings` `context`: free TP only offers partial post IDs (§8). | Omit `context` in v1; README and LATER unchanged. |
| F | `manual_translation_only` changes whether `enabled` actually re-translates (§9, §10). | Leave it out of v1 output. Mention it in the `reset` description. |

---

## 14. Gettext strings (Stage 2b discovery)

Answers to REQUIREMENTS §5.5, read in TranslatePress 3.3.8 (same archive as above). Extra paths: `P` = `TP/includes/gettext/class-process-gettext.php`, `M` = `TP/includes/gettext/class-gettext-manager.php`, `IU` = `TP/includes/queries/class-gettext-insert-update.php`, `Q` = `TP/includes/queries/class-query.php`.

### 14.1 Tables, columns and addressing

**Per-language table** `{prefix}trp_gettext_{lang}` (lower-cased locale, **no default-language part**), built by `TRP_Query::get_gettext_table_name()`, filter `trp_table_name_gettext`; invalid codes return the sentinel `trp_language_code_is_invalid_error` (`Q:1484-1490`). Created in `check_gettext_table()` (`TP/includes/queries/class-gettext-table-creation.php:47-57`):

| Column | Type |
|---|---|
| `id` | `bigint(20) AUTO_INCREMENT PRIMARY KEY` |
| `original` | `longtext NOT NULL` (legacy copy of the text) |
| `translated` | `longtext` (nullable) |
| `domain` | `longtext` (legacy copy) |
| `status` | `int(20)` (no default) |
| `original_id` | `bigint(20)` (nullable) |
| `plural_form` | `int(20)` (nullable) |

Indexes: **UNIQUE `gettext_original_plural_unique (original_id, plural_form)`**, `index_name (original(100))`, FULLTEXT on `original` (`:56`, `:63-68`). On older sites the unique index is added by the gettext optimisation migration; `gettext_tables_optimization_is_ready()` checks for it on every gettext table (`TP/includes/class-upgrade.php:548-584`).

**A gettext table exists for the default language too**: the default language is always part of `translation-languages` (`TP/includes/class-settings.php:236-237`), and gettext overrides are applied in it (§14.2; only the marker wrapping is skipped there, `P:274`).

**Originals** `{prefix}trp_gettext_original_strings` (`Q:1177-1179`, `class-gettext-table-creation.php:87-107`): `id` PK, `original TEXT NOT NULL`, `domain TEXT NOT NULL`, `context TEXT NULL`, `original_plural TEXT NULL`, `lookup_hash char(32) NOT NULL` (UNIQUE). `lookup_hash = md5( original . "\x1F" . domain . "\x1F" . context )` where an empty or `NULL` context is replaced by the sentinel **`trp_context`** (`Q:1229-1244`). One originals row is therefore one (original, domain, context) triple; the plural text hangs off it.

**Meta** `{prefix}trp_gettext_original_meta`: `meta_id`, `original_id`, `meta_key`, `meta_value` (`class-gettext-table-creation.php:124-130`). The gettext scan writes `in_email = yes` there (`TP/includes/string-translation/class-gettext-scan.php:153-159`).

**Addressing.** A translation row is `gettext_{lang}.original_id = gettext_original_strings.id` plus `plural_form`. The unique key means **one row per (`original_id`, `plural_form`) per language**: no duplicate-row problem like decision A, as long as the unique index exists. Rows with `original_id` `NULL` or `0` are pre-migration legacy rows, matched by TP only by `original` + `domain` + `plural_form` text (`Q:1382-1391`); they cannot be addressed by `original_id`.

TP's runtime and editor read `original`/`domain` from the originals table and fall back to the legacy columns only when the join is empty (`Q:1305-1310`, `M:67-71`).

### 14.2 Rendering: TP table vs the plugin's own `.mo` file

WordPress computes the translation from the loaded `.mo` first; TP filters it afterwards at priority 100 on `gettext`, `gettext_with_context`, `ngettext`, `ngettext_with_context` (`M:164-182`).

1. On `init`, `create_gettext_translated_global()` loads the language's rows into a request map keyed `context::plural_form::domain::original` (`M:44-82`, hook `TP/class-translate-press.php:517`). Normally it uses **`get_runtime_gettext_strings()`**, which loads only rows with `translated <> ''` and **`status IN (1, 2)`** (`Q:1318-1340`; docblock: "Language-file matches use status 4 and are intentionally left to WordPress gettext"). Before the runtime-status migration has finished, or in editor preview, it loads every row instead (`M:59-61`, `M:138-141`).
2. In `process_gettext_strings()`, when a map row exists with a non-empty translation **different from what WordPress returned** and `is_sprintf_compatible()`, TP replaces WordPress's text with `trp_sanitize_string( $row['translated'] )` (`P:196-208`).

**Answer: yes, a status-2 row with a non-empty translation overrides the `.mo` translation**, for every request processed in that language. A status-4 row is never loaded, so WordPress's current `.mo` text is served. The override applies even when the domain's `.mo` is not loaded in that locale (`P:179-181` only resets WordPress's value to the original; step 2 still runs).

Conditions under which TP does **not** apply the override (so a write has no visible effect):
- The stored text fails `is_sprintf_compatible()` against the reference text (14.6). TP **silently falls back** to the `.mo`/original; no error is shown.
- Domain `translatepress-multilingual`, `trp-edit-translation=true`, or AJAX actions starting with `trp_` (`P:117-122`).
- Strings that are empty or only punctuation/digits after a trim (`P:109-113`).
- wp-admin, `wp-login.php`, `xmlrpc.php` (`processing_gettext_is_needed()`, `M:143-162`).
- The current locale is not a TP language (`P:139-143`).

No object cache or transient is involved (none in `includes/gettext/` or the gettext queries); the map is rebuilt from the database on every request. Full-page caches apply as for regular strings (§7).

**Stale status-4 text.** A status-4 row's `translated` is a snapshot of the `.mo` taken when TP first saw the string (`P:802-825`, `M:473-495`); it is not used at render, so after a plugin update the displayed text can differ from the stored one [inferred].

### 14.3 Statuses and render-time inserts

| Value | Gettext meaning | Written by |
|---|---|---|
| 0 | not translated (`translated` NULL/`''`) | `insert_gettext_strings()` when the translation equals the original/plural or is empty (`IU:58-61`); editor save with empty text (`TP/includes/class-editor-api-gettext-strings.php:93`) |
| 1 | machine translated | gettext machine translation at shutdown (`M:546-630`, queue status at `P:739`, `M:528`) |
| 2 | human: overrides the `.mo` | editor save with non-empty text (`class-editor-api-gettext-strings.php:93`); insert with no explicit status (`IU:62-65`); the runtime-status migration for rows whose text differs from the `.mo` (`TP/includes/class-upgrade.php:1406-1434`) |
| 4 | text comes from the language file | first sighting with a `.mo` translation (`P:802-825`, `P:601-611`), back-fill when a `.mo` translation appears later (`P:210-219`, `M:473-495`) |

Status 3 (similar) is never used for gettext (no reference in the gettext classes). TP's String Translation labels 4 "Gettext file translation" (`TP/includes/string-translation/class-string-translation.php:178`).

**Rows are inserted at render time, and machine translation spends quota then:**
- Each front-end request in a TP language collects strings missing from the map (`P:242`, `P:802-825`) and, at `shutdown`, inserts the missing rows and updates status-0 rows that now have a `.mo` translation (`M:345-412`, hook `TP/class-translate-press.php:520`).
- Strings with no `.mo` translation are queued and machine-translated at shutdown when automatic translation is available, never into a language whose ISO code is `en` (`P:687-744`, `P:886-906`, `M:546-630`), sharing the request time budget (`M:576-579`).
- Editor preview inserts rows synchronously (`P:228-240`, `P:591-625`).
- String Translation's "missing gettext strings" AJAX inserts rows for **every other plural form** from the `.mo` (`TP/includes/string-translation/class-string-translation-api-gettext.php:55-91` → `M:782-912`).
- Instantiating plural forms can `update_option( 'trp_db_stored_data' )` (`TP/includes/gettext/class-plural-forms.php:67-98`).

**Impact:** gettext reads must be direct `SELECT`s, as for regular strings. Never call `TRP_Process_Gettext`, `TRP_Gettext_Manager`, `TRP_Plural_Forms`, the editor or the String Translation gettext API. `get_gettext_table_name()` and `get_table_name_for_gettext_original_strings()` are pure (name + filter) and safe.

### 14.4 Plural forms

- `_n()`/`_nx()` pass `$number`; TP turns it into an index with the language's `Plural-Forms` header (`P:145`, `TP/includes/gettext/class-plural-forms.php:39-45`, `:101-119`). Without a number (`__()`, `_x()`), the index is 0.
- The header comes from `trp_db_stored_data['gettext_plural_forms_header'][lang]`, filled once from WordPress core's `default` text domain for that locale, fallback `nplurals=2; plural=n != 1;` (`class-plural-forms.php:67-98`, `:111-119`).
- **One row per plural form index that has actually been rendered** (or back-filled by String Translation, 14.3), keyed by (`original_id`, `plural_form`). A language with three forms may have one, two or three rows.
- The singular is `original`, the plural is `original_plural` on the originals row. For `plural_form > 0`, TP's sprintf reference is **`original_plural`** (`P:202`, `P:232`, `M:565`); machine translation sends `original_plural` for those rows (`M:565`).
- The override replaces the whole string: `str_replace( trim( $text ), $translated, $text )` with the singular `$text` (`P:204`) [inferred: effectively the stored text].

**Impact:** plural rows are as safe to write as singular ones, provided `Validator` compares a `plural_form > 0` row against `original_plural`. No scope reduction is needed. "No row, no write" applies per plural form.

### 14.5 How the gettext editor saves

`wp_ajax_trp_save_translations_gettext` → `TRP_Editor_Api_Gettext_Strings::gettext_save_translations()` (`TP/class-translate-press.php:372`, `class-editor-api-gettext-strings.php:81-125`):

1. capability `trp_translating_capability` (default `manage_options`), nonce, languages restricted to `translation-languages` **including the default language** (`:82-88`);
2. `translated = trp_sanitize_string( $translated )` (strip script, strip `%xx`, `wp_kses_post`); **status = 2 if non-empty, else 0** (`:92-93`). No URL special case, unlike regular strings;
3. `update_gettext_strings( …, array( 'id', 'translated', 'status' ) )`: an `INSERT … ON DUPLICATE KEY UPDATE` upsert on `id` (`IU:354-419`);
4. `remove_possible_duplicates( …, 'gettext' )`: a **DELETE** of other rows with the same binary `original` + `domain` + `plural_form`, **ignoring context** (`Q:2049-2085`, condition at `:2078`) [inferred: can delete a different context's row];
5. `do_action( 'trp_save_editor_translations_gettext_strings', … )` (`:120`). **No listener in free TP** (grep of the whole plugin).

The editor has no approve action and does not distinguish status 4: re-saving a status-4 text turns it into 2.

**Impact:** same mechanics as §5 for regular strings: a conditional `UPDATE {gettext} SET translated, status WHERE id AND original_id AND plural_form AND BINARY COALESCE(translated,'') = %s AND status = %d`; never insert, never delete, skip steps 4 and 5 (decision D applies); same `Validator` → `wp_kses` → `trp_sanitize_string()` pipeline and `sat_sanitization_changed`.

### 14.6 Placeholders

TP's guard `is_sprintf_compatible( $translated, $reference )` (`P:1052-1089`, filter `trp_check_sprintf_compatibility`):
- token pattern `%(?:\d+\$)?[-+0 #]*(?:'.)?\d*(?:\.\d+)?[bcdeEfFgGhHosuxX%]`, `%%` dropped;
- **both lists sorted and compared for equality**: same tokens, same multiplicity, any order. `%s %s` → `%1$s %2$s` and `%s` → `%d` are both rejected, although the count is equal.

So with TP's check on, a placeholder mismatch does not cause a PHP error through TP's override path; the translation is **silently not shown**. The PHP-error risk exists only if a site turns the filter off, or if the plugin itself uses the stored text elsewhere [inferred].

`Validator` today compares the **count** only (`src/Validator.php`, `placeholder_count()`), with a slightly different pattern (no `'x` padding, no `h`/`H`). A gettext write that passes `Validator` could therefore be stored and never rendered.

Also: `trp_sanitize_string()` removes `%xx` hex pairs, so tokens such as `%02d` or `%1d` are stripped at render (`P:204`, `TP/includes/functions.php:213-221`). The existing `sat_sanitization_changed` check already rejects such writes.

**Impact:** for gettext, `sat_placeholder_mismatch` must use TP's rule (sorted token lists equal, TP's pattern), against `original` or `original_plural` (14.4).

### 14.7 Text identity and matching

- Gettext originals are the raw PHP strings passed to `__()` etc., after TP strips nested markers (`P:105-106`); they are **not** HTML-escaped by TP [inferred from the call path: no encoding step between the filter argument and the insert]. `Validator::normalize()` still applies for text lookup.
- Exact lookup for `get-strings` by text: `BINARY original` on `gettext_original_strings`, optional `domain`, optional `context` (an empty or absent context is stored as `NULL`, `''` or `trp_context`; compare via `COALESCE(NULLIF(context,''),'trp_context')` as TP does, `Q:1417`).
- Output `context`: `null` for `NULL`, `''` and `trp_context`.

### 14.8 Schema guard inputs (gettext)

- `trp_gettext_{lang}`: `id`, `original_id`, `plural_form`, `translated`, `status`, `domain`.
- `trp_gettext_original_strings`: `id`, `original`, `domain`, `context`, `original_plural`.
- Index `gettext_original_plural_unique` on each gettext table (guarantees one row per address; TP itself treats its absence as "migration not done", `class-upgrade.php:580`).

### 14.9 Open questions for the owner (Stage 2b)

| | Question | Recommendation |
|---|---|---|
| G1 | Status 4 has no place in the three exposed statuses. | Expose it as a fourth status **`language_file`** for gettext (filterable in `search-strings`, counted in `get-stats`). |
| G2 | Rules on a `language_file` row. | `translate`: allowed, becomes `human` (that is the override); no `overwrite_reviewed` needed. `approve`: outcome `unchanged`, not written (writing 2 would freeze today's `.mo` text and ignore later plugin updates). `reset`: allowed; describe that TP refills it from the `.mo` (status 4) on the next view, so it is effectively a no-op. |
| G3 | `reset` on a `human` gettext row. | Allowed with `overwrite_reviewed`, as for regular. Description: the `.mo` text returns on the next view if one exists, otherwise machine translation (if on) or the original. |
| G4 | Placeholder rule: TP's sorted-token equality is stricter than the current count check. | Use TP's rule **for both sources** (one rule, simpler, and harmless for regular strings, which rarely carry printf tokens). Adds 2 `ValidatorTest` cases (reordered positional tokens pass; `%s`→`%d` fails). Alternative: gettext only. |
| G5 | Default language: TP has a gettext table for it and applies overrides there (changes the site's source-language UI text). | Keep the §5.2 rule: default language rejected for gettext too (`sat_invalid_language`). |
| G6 | Legacy rows with `original_id` `NULL`/`0`. | Exclude from reads and writes, as regular rows with `original_id > 0` only. |
| G7 | Missing `gettext_original_plural_unique` index. | Treat as schema mismatch: gettext writes return `sat_schema_unsupported`; reads still work. Then no duplicate-row logic is needed for gettext. |
| G8 | `Strings.php` is already 511 lines; gettext adds a parallel set of queries. | Add `src/Gettext.php` for the gettext table access (mirrors `Strings`), keep one schema guard in `Strings`. Record the split in REQUIREMENTS §8. |

No part of §5.5 is unsafe or pointless: plural writes are safe with the right reference text (14.4), and status-2 writes do override the `.mo` (14.2). The scope is unchanged, subject to G1–G8.
