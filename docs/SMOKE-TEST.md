# Smoke test

Manual check on the dev site (free TranslatePress), through the hosting connector's `execute_ability` or `/wp-json/wp-abilities/v1/abilities/string-abilities/<name>/run` (GET for reads). About 10 minutes. Use real site strings while testing, but never copy them into the repo, an issue or a commit message.

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

Added in Stage 2 (REQUIREMENTS §9).
