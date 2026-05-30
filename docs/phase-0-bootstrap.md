# Phase 0 — Repo Bootstrap

## Goal & scope

Establish the `haddowg/json-api` repository with all foundational tooling, CI, and conventions in place so subsequent phases can focus purely on code. No yin source code is ported in this phase.

**In scope:**

- Repository created under the `haddowg` GitHub organisation
- `composer.json` with metadata, autoloading, minimum PHP, and dev dependencies
- Directory skeleton (`src/`, `tests/`, `docs/`)
- CI workflow on GitHub Actions
- Static analysis configuration (PHPStan)
- Code style configuration (PHP-CS-Fixer)
- Test framework configuration (PHPUnit) including spec-section group convention
- Coverage tooling (pcov) and Codecov integration
- Release automation (release-please, conventional commits)
- Dependency updates (Dependabot)
- Licence, README skeleton, CONTRIBUTING notes
- Master plan and phase plans committed to `docs/`

**Out of scope:**

- Porting yin source code (Phase 1)
- Documentation content beyond plan files and the README skeleton (Phase 5)
- Any JSON:API logic

## Prerequisites

- GitHub organisation `haddowg` exists and the maintainer has push rights
- Decision to start at `0.1.0` (deferred until Phase 1 actually publishes something)

## Kick-off

This is the first phase, so there is no prior handover to review. Before starting work:

1. Read `docs/PLAN.md` end-to-end to ensure the master plan still reflects current intent.
2. Resolve every open question in this document (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
3. Commit any plan revisions as a single commit before starting implementation.

## Task list

### Repository

- [ ] Create empty `haddowg/json-api` repository on GitHub (private or public per maintainer preference; public before 1.0 is fine)
- [ ] Initialise locally, set default branch to `main`
- [ ] Add MIT licence. Since this package is substantially based on woohoolabs/yin (also MIT), the `LICENSE` file must include:
  - A copyright line for the haddowg organisation / project maintainers
  - A copyright line preserving the original yin authors (Woohoo Labs and contributors), with a reference to the upstream repository
  - The standard MIT licence text covering both copyright holders
- [ ] Add `.gitignore` for PHP/Composer/IDE/OS artefacts
- [ ] Add `.gitattributes` to exclude tests/docs/dev files from Composer dist

### Composer & autoload

- [ ] `composer.json` with:
  - `name`: `haddowg/json-api`
  - `description`: server-side JSON:API 1.1 library for PHP
  - `type`: `library`
  - `require`: `php: ^8.3`, `psr/http-message: ^2.0`, `psr/http-factory: ^1.0`
  - `require-dev`: `phpunit/phpunit`, `phpstan/phpstan`, `friendsofphp/php-cs-fixer`, `nyholm/psr7`, `nyholm/psr7-server`
  - `autoload`: PSR-4 `haddowg\\JsonApi\\` → `src/`
  - `autoload-dev`: PSR-4 `haddowg\\JsonApi\\Tests\\` → `tests/`
  - `scripts`: `test`, `phpstan`, `cs-check`, `cs-fix`
- [ ] Verify `composer install` succeeds on PHP 8.3 and 8.4
- [ ] Commit `composer.lock`? Decision: no (library convention)

### Directory skeleton

- [ ] `src/` — empty (Phase 1 will populate)
- [ ] `tests/` — empty placeholder test to validate PHPUnit wiring
- [ ] `docs/` — contains `PLAN.md`, `phase-0-bootstrap.md`, `phase-1-core-port.md` (this document and its siblings)

### PHPUnit

- [ ] `phpunit.xml.dist` configured for `tests/` directory
- [ ] Bootstrap via Composer autoloader
- [ ] Coverage configuration using pcov, output to `coverage.xml` (clover) for Codecov
- [ ] Document spec-section group convention in `tests/README.md`:
  - Tests assert spec compliance use `@group spec:<section>` where `<section>` is the spec anchor (e.g. `spec:document-structure`, `spec:fetching-resources`, `spec:ext-atomic`)
  - Document the convention with an example
- [ ] Single trivial test passing to verify the toolchain

### Static analysis

- [ ] `phpstan.neon.dist`:
  - `level: 9`
  - Paths: `src`, `tests`
  - Reasonable baseline empty initially; add ignores only with justification
- [ ] `composer phpstan` script runs `vendor/bin/phpstan analyse`

### Code style

- [ ] `.php-cs-fixer.dist.php`:
  - PER-CS 2.0 ruleset (`@PER-CS2.0`)
  - Strict types declaration enforced
  - Reasonable additional rules: `no_unused_imports`, `ordered_imports`, `declare_strict_types`
- [ ] `composer cs-check` and `composer cs-fix` scripts

### CI workflow

- [ ] `.github/workflows/ci.yml`:
  - Triggers: push to `main`, pull requests
  - Jobs:
    - `tests`: matrix on PHP `[8.3, 8.4, 8.5]` × Composer dependency strategy `[lowest, highest]`. Runs PHPUnit with coverage on the `highest`/`8.5` cell only; uploads to Codecov.
    - `phpstan`: PHP 8.4, runs `composer phpstan`
    - `cs`: PHP 8.4, runs `composer cs-check`
  - Use `shivammathur/setup-php` with `pcov` extension on the coverage cell
- [ ] Confirm CI is green on initial push

### Release automation

- [ ] `.github/workflows/release-please.yml` configured for a PHP library
- [ ] `release-please-config.json` and `.release-please-manifest.json` at repo root
- [ ] Document conventional commits expectation in `CONTRIBUTING.md`

### Dependency updates

- [ ] `.github/dependabot.yml`:
  - Composer ecosystem, weekly schedule
  - GitHub Actions ecosystem, weekly schedule

### Documentation

- [ ] `README.md` skeleton:
  - Project name, one-line description, badges (CI, coverage, packagist when published)
  - Status notice: pre-1.0, breaking changes possible
  - Installation placeholder
  - Quick example placeholder (filled in Phase 1)
  - Link to `docs/PLAN.md`
  - **Acknowledgements section** — explicit credit and thanks to [woohoolabs/yin](https://github.com/woohoolabs/yin) and its original authors (Woohoo Labs and contributors), noting that this package is a fork and substantial portions of the codebase derive from their work
  - Licence section noting MIT and dual copyright (haddowg + original yin authors)
- [ ] `CONTRIBUTING.md` with conventional commits requirement and basic dev workflow
- [ ] Commit `docs/PLAN.md`, `docs/phase-0-bootstrap.md`, `docs/phase-1-core-port.md`

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale |
|---|---|---|
| 2026-05-30 | CI test matrix is PHP `[8.3, 8.4, 8.5]` × deps `[lowest, highest]`; coverage on `highest`/`8.5` | Plan was drafted pre-8.5; 8.5 is now stable. Floor stays 8.3 (bump only on majors); adding 8.5 gives forward coverage at small CI cost. |
| 2026-05-30 | Pin `phpunit/phpunit: ^12` in `require-dev` | PHPUnit 12 supports PHP 8.3–8.5; native attributes only (annotations removed in 12). PHPUnit 13 drops 8.3, which is our floor. Mirrors the Phase 1 decision-log entry. |
| 2026-05-30 | Do **not** commit `composer.lock` | Standard library convention; consumers resolve their own versions. CI exercises both `lowest` and `highest` strategies, so reproducibility is covered without a committed lock. |
| 2026-05-30 | MIT `LICENSE` copyright line: `Copyright (c) 2026 Gregory Haddow`, alongside a preserved `Copyright (c) Woohoo Labs and contributors` line referencing woohoolabs/yin | Package is a fork of yin (MIT); dual attribution is a licence obligation and a deliberate acknowledgement. Personal-name attribution chosen over the `haddowg` org handle. |

## Open questions

_All resolved at kick-off (2026-05-30) — see decision log._

- ~~Composer lock file: confirm convention (library = no commit).~~ **Resolved: no commit.**
- ~~README badges: add Packagist badge once first release is cut; defer until then.~~ **Resolved: defer Packagist badge to first release; CI badge added now.**
- ~~PHPUnit major version: select the latest stable that supports PHP 8.3 and 8.4.~~ **Resolved: `^12`.**
- ~~CI PHP matrix (surfaced at kick-off): the plan predates PHP 8.5.~~ **Resolved: `[8.3, 8.4, 8.5]`.**
- ~~LICENSE copyright holder (surfaced at kick-off).~~ **Resolved: `Gregory Haddow`.**

## Acceptance criteria

The phase is done when all of the following hold:

1. The repository exists and is accessible at `github.com/haddowg/json-api`.
2. All task-list items are checked off.
3. CI on `main` is green across the full PHP × dependency matrix.
4. Codecov receives a coverage report from CI.
5. `composer install`, `composer test`, `composer phpstan`, and `composer cs-check` all succeed locally on PHP 8.3 and PHP 8.4.
6. `docs/PLAN.md`, `docs/phase-0-bootstrap.md`, and `docs/phase-1-core-port.md` are present in the repository.

### Verification plan

Run each of these in order from a clean clone of the repo:

```bash
git clone git@github.com:haddowg/json-api.git
cd json-api
composer install
composer test           # PHPUnit runs, trivial test passes
composer phpstan        # exits 0
composer cs-check       # exits 0
vendor/bin/phpunit --group spec:document-structure  # runs zero tests cleanly (proves group convention works)
```

Then verify on GitHub:

- Actions tab: the latest CI run on `main` is green.
- Codecov dashboard: a coverage report exists for the latest commit.
- Dependabot: enabled with composer + actions ecosystems configured.
- Release-please: a `chore(main): release` PR appears (or the workflow run is visible if no commits since bootstrap warrant a release PR).

## Handover output

Before declaring the phase complete, produce the following for Phase 1:

1. **Repository URL** confirmed and recorded in the master plan.
2. **CI baseline** — link to a green CI run on `main`, captured in the decision log.
3. **Codecov project token** stored as a GitHub Actions secret (if Codecov requires one).
4. **Phase 1 plan reviewed** — sanity-check `docs/phase-1-core-port.md` against any decisions made during bootstrap (e.g. if PHPUnit version chosen affects test syntax). Append corrections to Phase 1 plan if needed.
5. **Update master plan status table** — set Phase 0 to `Complete`, Phase 1 to `Ready`.
6. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 1.
7. **Decision log finalised** — every meaningful decision from this phase is captured either in this document or the master `PLAN.md` cross-phase log.
