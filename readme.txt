=== String Abilities for TranslatePress ===
Contributors:       jacamac
Tags:               translatepress, translation, abilities, ai, mcp
Requires at least:  6.9
Tested up to:       7.1
Requires PHP:       8.1
Stable tag:         0.3.0
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

WordPress abilities for reading, reviewing and correcting TranslatePress string translations.

== Description ==

Registers a small set of WordPress Abilities (namespace `string-abilities/`) so an AI agent or any automation can work on TranslatePress string translations through a narrow interface instead of a browser session with full admin rights.

The plugin runs no MCP server and adds no REST routes of its own. Abilities are exposed by whatever already exposes the site's abilities: WordPress core (`/wp-json/wp-abilities/v1/`), the MCP Adapter plugin, or a hosting provider's connector.

Abilities:

* `get-stats` – untranslated / machine / human counts per language.
* `search-strings` – paged search within one language.
* `get-strings` – specific strings in several languages at once, by id or by source text.
* `get-auto-translation-settings` – whether automatic translation is on, the engine and the languages. Never returns API keys or license data.
* `update-translations` – translate, approve or reset strings in several languages at once. Dry run by default; a write fails if the translation changed since it was read; human translations are protected; HTML and placeholders are checked.
* `revert` – undo one change or a whole batch.
* `get-change-log` – history of the changes.

`get-stats`, `search-strings`, `get-strings`, `update-translations` and `get-change-log` also work on plugin and theme strings (`source: gettext`).

Reads are plain database queries: they never trigger machine translation or create rows. Writes only update existing rows; they never insert or delete TranslatePress rows or touch source text. Define `SAT_READ_ONLY` to disable writes. The full reference is in README.md on GitHub.

Requires TranslatePress – Multilingual (free). Paid add-ons are not required.

Not affiliated with or endorsed by TranslatePress or Cozmoslabs.

== Installation ==

1. Download the zip from the latest GitHub release.
2. In WordPress, go to Plugins → Add New → Upload Plugin and install it.
3. Activate. Administrators get the `sat_read` and `sat_manage` capabilities; a `translation_agent` role is created with only those plus `read`.

Updates then appear in the normal Plugins screen: the plugin checks its GitHub releases itself (bundled Plugin Update Checker).

== Changelog ==

See CHANGELOG.md.
