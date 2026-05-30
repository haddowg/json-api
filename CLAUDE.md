# CLAUDE.md — executor playbook

Executor-facing playbook for `haddowg/json-api`. This file is read by future
Claude Code sessions (including after context compaction or session restart) to
keep work consistent. It is **not** consumer documentation — consumer docs are
produced in Phase 5 under `docs/`.

## Project orientation

`haddowg/json-api` is a modern, server-side JSON:API 1.1 library for PHP 8.3+.
It is a **derivative work** based on [woohoolabs/yin](https://github.com/woohoolabs/yin)
(MIT) — substantial portions of the codebase derive from yin — but it is **not a
fork**: there is no upstream tracking relationship and no commitment to yin's
public API. Always credit yin as the original work; never describe this package
as a "fork".

- Spec: [JSON:API 1.1](https://jsonapi.org/format/1.1/)
- Namespace: `haddowg\JsonApi\…`; minimum PHP 8.3
- The master plan and phase plans live in `docs/`; start at [`docs/PLAN.md`](docs/PLAN.md).

Pattern entries (value objects, exceptions, resources, hydrators, middleware,
etc.) are added to this file as each component kind is first built, starting in
Phase 1.

## Git conventions

### Conventional Commits (required)

Every commit message MUST follow [Conventional Commits](https://www.conventionalcommits.org/).
Commit messages and PR titles drive automated versioning and the changelog via
[release-please](https://github.com/googleapis/release-please).

Format: `type(optional scope): description`

Common types:

| Type | Use for | Version impact (pre-1.0) |
|------|---------|--------------------------|
| `feat:` | A new feature | minor |
| `fix:` | A bug fix | patch |
| `docs:` | Documentation only | none |
| `test:` | Tests only | none |
| `refactor:` | Neither fixes a bug nor adds a feature | patch |
| `chore:`, `ci:`, `build:` | Tooling / maintenance | none |

- Use the imperative mood ("add", not "added"/"adds").
- Signal a breaking change with `!` after the type/scope (e.g. `feat!:`) or a
  `BREAKING CHANGE:` footer. While the package is `0.x`, breaking changes bump
  the **minor** version.

### Pull requests

**PRs are squash-merged.** The squash commit takes the **PR title** as its
subject, so:

- The **PR title MUST be a valid Conventional Commit** (e.g.
  `feat: add cursor-based pagination`, `chore: bootstrap repository tooling`).
  It becomes the single commit on `main` and feeds release-please — a
  non-conforming title breaks versioning.
- The **PR description** reads as natural prose, as if pitched by an external
  contributor proposing the change — not a templated form. Do **not** use literal
  "What"/"Why" headings. Convey the purpose and motivation in a short paragraph
  (optionally a few bullets for notable points), without walking through
  implementation specifics — the diff is the record of how. Describe the change
  on its own terms: do **not** reference internal phases, the master plan, or
  this playbook; a reader of the public repo has no context for them.
- Individual commits on the branch need not be individually meaningful (they are
  squashed away), but should still use Conventional Commit messages for a clean
  in-progress history.

## Operational rules

These apply to all phases (expanded in Phase 1 from the master plan):

- **Single-threaded until a pattern is established.** Build the first instance of
  a component kind sequentially in the main worktree; write its pattern entry
  here before fanning out.
- **Batching** is eligible only once (a) the pattern entry exists, (b) one full
  instance is built, tested, and merged, and (c) remaining work is mechanical.
- **Parallel work uses git worktrees**, one per subagent; convergence (merging
  back) is sequential with CI green at each step.
- **Tests port/build file-by-file alongside their implementation** — never
  deferred to a bulk end-of-phase pass.
- **Consolidation review after every fan-out**, recorded in the phase decision log.

## Tooling

Run before pushing (CI enforces all three across PHP 8.3/8.4/8.5 × lowest/highest):

```bash
composer test       # PHPUnit (attributes only, no annotations)
composer phpstan    # PHPStan level 9
composer cs-check   # PHP-CS-Fixer, PER-CS 2.0
```

Tests asserting a spec requirement are tagged `#[Group('spec:<section>')]` — see
[`tests/README.md`](tests/README.md).
