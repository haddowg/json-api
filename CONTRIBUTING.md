# Contributing

Thanks for your interest in contributing to `haddowg/json-api`.

## Requirements

- PHP 8.3, 8.4, or 8.5
- [Composer](https://getcomposer.org/) 2.x

## Getting started

```bash
git clone https://github.com/haddowg/json-api.git
cd json-api
composer install
```

## Development workflow

Run the full local check set before opening a pull request:

```bash
composer test       # PHPUnit test suite
composer phpstan    # PHPStan static analysis (level 9)
composer cs-check   # Coding-standard check (PER-CS 2.0)
```

To apply coding-standard fixes automatically:

```bash
composer cs-fix
```

CI runs the test suite across PHP 8.3, 8.4, and 8.5 against both the `lowest`
and `highest` Composer dependency strategies, plus PHPStan and coding-standard
jobs. All must pass.

## Tests

- Tests mirror the `src/` directory layout.
- Tests asserting a JSON:API spec requirement are tagged with a
  `spec:<section>` group — see [`tests/README.md`](tests/README.md).
- PHPUnit 12 is used; tests use **attributes** (`#[Test]`, `#[DataProvider]`,
  `#[Group]`), not docblock annotations.

## Conventional Commits

This project **requires** [Conventional Commits](https://www.conventionalcommits.org/).
Commit messages drive automated versioning and the changelog via
[release-please](https://github.com/googleapis/release-please).

Common types:

| Type | Use for | Version impact (pre-1.0) |
|------|---------|--------------------------|
| `feat:` | A new feature | minor |
| `fix:` | A bug fix | patch |
| `docs:` | Documentation only | none |
| `test:` | Tests only | none |
| `refactor:` | Code change that neither fixes a bug nor adds a feature | patch |
| `chore:`, `ci:`, `build:` | Tooling / maintenance | none |

Indicate a breaking change with a `!` after the type (e.g. `feat!:`) or a
`BREAKING CHANGE:` footer. While the package is `0.x`, breaking changes bump the
minor version and are documented in the changelog.

### Examples

```
feat: add CursorBasedPage link emission
fix: reject unknown media-type parameters per spec
docs: document the profile registration flow
feat!: rename Pagination to Paginator
```

## Pull requests

PRs are **squash-merged**, and the squash commit uses the **PR title** as its
subject. Therefore:

- The **PR title must be a valid Conventional Commit** (e.g.
  `feat: add cursor-based pagination`). It feeds release-please, so a
  non-conforming title breaks versioning.
- The **PR description** reads as natural prose describing the change and its
  motivation — write it the way you'd pitch the change to a maintainer, not as a
  filled-in template. Keep it focused on intent rather than implementation detail.
- Keep PRs focused; one logical change per PR where practical.
- Ensure `composer test`, `composer phpstan`, and `composer cs-check` all pass.
- Reference any related issue in the PR description.
