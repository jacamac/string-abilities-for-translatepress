# Later

Ideas deliberately left out of v1. Each one is built only when a real need shows up.

| Idea | Why not now |
|---|---|
| Gettext (plugin/theme) strings | Different storage and keying; v1 covers page strings only |
| Glossary per language (`get-glossary` / `set-glossary`) with "expected term" warnings | Inflection and case changes (e.g. German) produce many false positives; `protected_terms` covers the safe part. Check whether the machine-translation engine's own glossary feature can be used instead |
| Length warnings for `<title>` / meta description | Needs reliable string context, which depends on Stage 0 findings |
| String context in all outputs (title / meta / content, post, URL) | Depends on what free TranslatePress records |
| `find-similar` (fuzzy match on originals to reuse earlier human translations) | Real work; exact lookup on the previous source text covers the common case |
| Orphan listing and purge (dry run, full row snapshot, revertible) | Needs reliable usage tracking |
| Rate limiting | Single trusted caller for now; capabilities and per-call limits suffice |
| Admin page (settings, change history, revert buttons) | Read-only constant and abilities cover v1 |
| URL slug translation | Feature of a paid add-on |
| CSV export/import | Not needed in the plugin: an agent can build the CSV from `search-strings` and apply it with `update-translations` |
| Dry-run token | Redundant with the required `expected_current` |
