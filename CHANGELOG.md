# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- `dev-latest` builds are stamped `X.Y.Z-dev.<run>` in the zip only, with the committed version set to the next release, so a test site following `develop` is offered every build and the release replaces them.

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
