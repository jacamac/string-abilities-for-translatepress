# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Plugin and theme strings (TranslatePress gettext tables): optional `source` (`regular` default, `gettext`) on `get-stats`, `search-strings`, `get-strings`, `update-translations` (per item, with `plural_form`) and `get-change-log`. Gettext items carry `plural_form`, `original_plural`, `domain` and `context`; `get-stats` and `search-strings` take a `domain` filter, `get-strings` with `originals` takes `domain` and `context`.
- Status `language_file` (TranslatePress status 4: text from the plugin's own `.mo` file). `approve` leaves it `unchanged`; `reset` on it is rejected with `sat_invalid_input`.
- Change log columns `source` and `plural_form` (`sat_db_version` 3); entries and `revert` items report them.
- Schema guard covers the gettext tables and their unique `(original_id, plural_form)` index; a mismatch only disables gettext (the index only gettext writes).
- `Validator::check()` gettext mode: `sat_placeholder_mismatch` uses TranslatePress's own rule (same tokens, any order), so a translation TranslatePress would silently ignore is rejected; with unit tests.

### Fixed
- Every `source: gettext` call failed with `Class "StringAbilities\Gettext" not found`: the plugin bootstrap did not load `src/Gettext.php`. CI (`bin/check-version.sh`) now fails if any file in `src/` is not `require_once`d by the main plugin file.

### Changed
- `update-translations` and `revert` items include `source` (and `plural_form` for gettext).

## [0.2.0] - 2026-10-23

### Added
- Write abilities `update-translations` (translate / approve / reset, dry run by default, `expected_current`, `overwrite_reviewed`, `protected_terms`, `note`, `batch_id`) and `revert` (by `change_id` or `batch_id`), plus `get-change-log`. Each write is one conditional `UPDATE` of the displayed row; strings without a row in a language are rejected with `sat_not_found`; text that `wp_kses` or TranslatePress's sanitising would alter is rejected with `sat_sanitization_changed`.
- Change-log table `{prefix}sat_changes` (`dbDelta`, option `sat_db_version`), created on activation or on first use after an update; `uninstall.php` drops it.
- Read-only switch: constant `SAT_READ_ONLY` or option `sat_read_only` makes `revert` and real `update-translations` writes return `sat_writes_disabled`; dry runs stay allowed and report `writes_disabled: true`.
- A bare `&` in a translation is converted to `&amp;` before validation, with warning `sat_ampersand_encoded`.
- Change log column `row_id` (`sat_db_version` 2): `revert` restores the exact row written, with the same conditional `UPDATE`; a row is never located by its text.
- `Validator::check()`: errors `sat_html_mismatch`, `sat_placeholder_mismatch`, `sat_too_long`, `sat_invalid_utf8`; warnings `sat_numbers_mismatch`, `sat_identical_to_original`, `sat_protected_term_missing`; with unit tests.

### Changed
- `dev-latest` builds are stamped `X.Y.Z-dev.<run>` in the zip only, with the committed version set to the next release, so a test site following `develop` is offered every build and the release replaces them. In branch mode the latest dev build stays offered even once installed (README).

## [0.1.0] - 2026-10-23

### Added
- Specification (`docs/REQUIREMENTS.md`), README with the planned ability reference, and project instructions.
- Stage 0 discovery (`docs/SCHEMA.md`) and TranslatePress internals notes (`docs/TRANSLATEPRESS-INTERNALS.md`).
- Read abilities `get-stats`, `search-strings`, `get-strings` and `get-auto-translation-settings`, in category `string-abilities`. Plain `SELECT`s only; duplicate rows resolved to the row TranslatePress displays and reported with `sat_duplicate_rows`.
- Normalised text matching (`Validator::normalize()`) for `get-strings` `originals` and the `search-strings` `query`, with unit tests.
- Capabilities `sat_read` and `sat_manage` granted to administrators on activation, and role `translation_agent`.
- Schema guard: abilities return `sat_schema_unsupported` when a TranslatePress table lacks an expected column (checked once per TranslatePress version and table set).
- `uninstall.php` removes the role, the capabilities and the plugin option.
- CI (gitleaks, private-terms check, PHPCS, PHPStan level 6, PHPUnit, version check), pre-commit leak checks, and a tag-triggered release workflow.

### Changed
- Specification and README updated with Stage 0 decisions: normalised text matching, conditional atomic writes, reset semantics, `manual_only` setting, duplicate-row and sanitisation codes, no `context` in v1.
- Plan extended with Stage 2b (0.3.0): plugin and theme (gettext) strings through a `source` parameter on the existing abilities.
- Updates through a bundled Plugin Update Checker and a rolling `dev-latest` build, as in the owner's other plugins (replaces Git Updater headers). Constant `SAT_UPDATE_BRANCH` in `wp-config.php` switches a test site to `dev-latest`; `dev-release.yml` publishes it after CI passes on `develop`.
- `search-strings` matching is case-insensitive; normalisation also maps `…` to `...`.

### Security
- `get-auto-translation-settings` returns allowlisted keys only: never API keys, license data or the notification email.
