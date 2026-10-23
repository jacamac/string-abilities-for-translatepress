# Later

Ideas deliberately left out of v1. Each one is built only when a real need shows up.

| Idea | Why not now |
|---|---|
| Glossary per language (`get-glossary` / `set-glossary`) with "expected term" warnings | Inflection and case changes (e.g. German) produce many false positives; `protected_terms` covers the safe part. Check whether the machine-translation engine's own glossary feature can be used instead |
| Length warnings for `<title>` / meta description | Free TranslatePress does not translate SEO meta tags (paid SEO Pack); no reliable string context |
| String context in outputs (post IDs, title / meta / content, URL) | Free TranslatePress only records partial, add-only post IDs (SCHEMA §8) |
| `find-similar` (fuzzy match on originals to reuse earlier human translations) | Real work; exact lookup on the previous source text covers the common case |
| Orphan listing and purge (dry run, full row snapshot, revertible) | Needs usage tracking the plugin would have to add itself; a purge could reuse TranslatePress's own delete routine (SCHEMA §13) |
| Firing `trp_save_editor_translations_regular_strings` after writes | No listener in free TranslatePress; revisit if an add-on needs it |
| Cleaning duplicate rows | TranslatePress has its own tool; the plugin only reports them |
| Engine language support in `get-auto-translation-settings` | TranslatePress's per-engine code mapping can make an HTTP call (INTERNALS §10 item 18); v1 returns the configured languages unfiltered |
| Exclude non-text strings (URLs, file names) from `search-strings` and `get-stats` | On a large site thousands of "untranslated" rows are image or asset URLs that need no action; an `exclude_urls` filter would make sweeps cleaner |
| `sat_numbers_mismatch`: treat unit notations as equivalent (`m2` vs `m²`, `cm2` vs `cm²`) | Observed false positive in production: a source writing `27m2` and a translation writing `27 m²` raise the warning; harmless (warning only) but noisy |
| Rate limiting | Single trusted caller for now; capabilities and per-call limits suffice |
| Admin page (settings, change history, revert buttons) | Read-only constant and abilities cover v1 |
| URL slug translation | Feature of a paid add-on |
| CSV export/import | Not needed in the plugin: an agent can build the CSV from `search-strings` and apply it with `update-translations` |
| Dry-run token | Redundant with the required `expected_current` |
