# CLAUDE.md — instructions for Claude Code

Full specification: `docs/REQUIREMENTS.md`. Read it before any work. This file holds the rules that apply to every session.

## Rules

1. **Minimum code.** Implement only what `docs/REQUIREMENTS.md` §5 lists. No abstraction "for later", no extra options, no extra abilities. Ideas go to `docs/LATER.md`, not into code.
2. **Lean testing.** Automated tests only for `Validator` (and pure write-rule functions if they exist). Never mock WordPress. Everything else is covered by `docs/SMOKE-TEST.md` on the dev site. Do not add tests beyond the list in §9 without asking.
3. **Public-review quality.** WordPress Coding Standards, `declare(strict_types=1)`, `$wpdb->prepare` for every query, escape every output, short functions, docblocks on public methods.
4. **Never write originals** (source-language strings). Never delete TranslatePress rows. Never touch TranslatePress settings.
5. **No real site data and no credentials** anywhere in the repo or in commit messages: no domain names, real strings, user names, emails, IDs, tokens or passwords from any live site. Fixtures are fictitious. The repo is public. CI runs gitleaks and a private-terms check (REQUIREMENTS §9); never weaken, skip or allow-list them to make a build pass — stop and ask instead.
6. **Free TranslatePress only.** Nothing may depend on a paid add-on.
7. **Verify, don't assume.** Anything about TranslatePress internals or the Abilities API comes from `docs/SCHEMA.md` (Stage 0) or the source code, never from memory. If unsure, stop and ask.
8. **README is the ability reference.** Any change to an ability's name, input, output, error or warning updates `README.md` in the same commit.
9. **Every behaviour change adds a line to `CHANGELOG.md` under `Unreleased`.**
10. **Read `docs/TRANSLATEPRESS-INTERNALS.md` before touching TranslatePress internals or the Abilities API.** When you learn something new about either, add it to that file with a file:line reference and mark it read-in-code or inferred. If the TranslatePress version changes, re-verify its references.

## Stages

Work one stage per session and stop at its exit criteria (REQUIREMENTS §11). Do not start the next stage without the owner's go-ahead.

- Stage 0 — `docs/SCHEMA.md` only, no plugin code.
- Stage 1 — 0.1.0, read abilities.
- Stage 2 — 0.2.0, write abilities.
- Stage 3 — 1.0.0, public release.

## Conventions

Mirror `github.com/jacamac/beds24-availability-calendar` (may be renamed to `availability-calendar-for-beds24`): read it at the start of Stage 1 and record any difference from the list below here.

- SemVer. `Version:` header = `SAT_VERSION` constant = `Stable tag:` in `readme.txt`; CI fails if they differ.
- Branches: work on `develop`; `main` is releases only.
- Release: merge to `main`, bump version, move `Unreleased` to the version in `CHANGELOG.md` (Keep a Changelog), sync `readme.txt`, commit `Release vX.Y.Z`, tag `vX.Y.Z`. `release.yml` builds the zip and the GitHub Release.
- Commit messages: imperative, short subject line (`Add get-stats ability`).
- CI: PHPCS (WordPress), PHPStan level 6 with WordPress stubs, PHPUnit, version check, gitleaks, private-terms check. Nothing else.
- The leak checks are the **first** thing set up in Stage 1, before any plugin code is committed.

## Differences from the reference repo

(Fill in at Stage 1.)
