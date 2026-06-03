# 1.0 readiness review

The final pass before tagging `1.0.0`. Tagging `1.0.0` starts the semver
commitment — after it, breaking changes require a major bump — so this review
confirms the public surface is deliberate, the spec coverage holds, and the inputs
the library accepts and outputs it produces are safe.

The library is feature-complete across the surface documented under
[`docs/`](README.md); this is a verification and release pass, not a feature pass.
Any gap surfaced here is either fixed now or filed as a known post-1.0 follow-up.

## Out of scope (deliberately)

Recorded so the omissions read as decisions, not oversights:

- **Performance baseline / benchmark suite** — not gating 1.0; revisit if a
  regression is suspected.
- **CHANGELOG curation** — release-please generates `CHANGELOG.md` from the
  conventional-commit history; there is nothing to hand-write.
- **`composer audit` / dependency advisories** — handled by Dependabot, not a step here.
- **`api-inventory.md`, `maintenance.md`** — the public docs and the ADRs
  (`docs/adr/`) already carry this; no separate inventory to maintain.
- **Generated post-1.0 plans** — see [Post-1.0 candidates](#post-10-candidates)
  below; a full plan is written only when work is scheduled.

## Tasks

### Spec-compliance verification

`docs/spec-compliance.md` already records every tracked requirement as
test-covered. This is a re-walk to confirm that still holds, not an audit to fix gaps.

- Walk each row; confirm the status is current and the named test still exists.
- Cross-check against the [JSON:API 1.1 spec](https://jsonapi.org/format/1.1/) that
  no MUST was missed.
- Run the spec suites per section (the groups listed in [`tests/README.md`](../tests/README.md)):

  ```bash
  for group in document-structure errors fetching-resources fetching-relationships \
               fetching-data inclusion-of-related-resources sparse-fieldsets sorting \
               pagination filtering crud content-negotiation extensions-and-profiles; do
    vendor/bin/phpunit --group "spec:$group" --testdox
  done
  ```

### Public API surface review

Walk every public class, interface, and enum under `src/` (the 15 top-level
namespaces). For each:

- Naming is idiomatic and consistent with its neighbours (`…Interface` suffix used
  or not, applied uniformly).
- Parameter and return types are narrowed as far as they can be.
- No `@internal` type leaks through a public method signature.
- Consumer-visible parametric types carry their `@template` parameters per the
  generics policy in `CLAUDE.md` — `PageInterface<T>` and the registry lookups, the types
  whose parameter survives to the use site. `DataResponse`, `OperationHandlerInterface`, and
  `FieldInterface` are deliberately **not** generic (the parameter erases at the
  serializer/handler boundary; see the policy for the per-type rationale).
- No dead methods, unused parameters, or leftover yin idioms remain.

Reconcile the `CLAUDE.md` component notes against the code and correct any drift.

*Optional, lightweight:* try a PHPStan level bump or extra strict rules; keep only
the fixes that genuinely improve type soundness, discard the noise.

### Security review (manual, one-time)

Focused on the inputs the library accepts and the outputs it produces:

- **Body parsing** — `json_decode` is depth-bounded and `JSON_THROW_ON_ERROR` is
  used everywhere a body is decoded; a deeply nested document cannot blow the stack.
- **Header parsing** — the `Accept` / `Content-Type` parsers
  (`src/Request/MediaType.php`) handle malformed input safely: no catastrophic
  backtracking, no unbounded loops.
- **Error responses** — production-mode error output does not leak stack traces,
  file paths, or environment unless debug is explicitly enabled.

Capture the consumer-facing recommendations in **`docs/security.md`** (e.g. put a
body-size limit in front of the application; keep dev-mode error verbosity off in
production).

### Release

- release-please currently sits at `0.0.0` with `bump-minor-pre-major`, so it will
  keep cutting `0.x`. To cut **`1.0.0`**, set `"release-as": "1.0.0"` in
  `release-please-config.json` for the next release (or tag manually).
- Merge the release PR; confirm the tag publishes to Packagist.
- Smoke-test a clean install:

  ```bash
  composer create-project haddowg/json-api ./test-install
  cd test-install && composer test
  ```

  Confirm the [getting-started](getting-started.md) example runs against the install.

## Post-1.0 candidates

Out of scope for 1.0; independent of each other; scheduled on demand. A full plan is
written when one is picked up — listed here only so the direction is recorded.

- **Atomic Operations extension** — the JSON:API `ext` for batching operations in one
  request. The seams are already in place (local IDs on resource identifiers, `ext`
  media-type negotiation) — see
  [ADR 0011](adr/0011-atomic-operations-deferred-seams-in-place.md). What's left is
  cross-document `lid` resolution and an operation dispatcher.
- **Attribute-driven resources/hydrators** — `#[ResourceType]`, `#[Attribute]`,
  `#[Relationship]`, `#[Profile]` attributes as a lower-boilerplate alternative to the
  class-based fluent schema.
- **OpenAPI generation** — walk a `Server`'s registered resources, profiles, and
  handlers to emit OpenAPI 3.x; per-resource components derive from the same `FieldInterface`
  + `ConstraintInterface` metadata the JSON Schema compiler uses. No 1.0 design dependency.

## Acceptance

1. Every spec group runs green; `docs/spec-compliance.md` is confirmed accurate.
2. The public-API surface review is complete and any naming/consistency fixes merged.
3. `docs/security.md` exists with consumer-facing recommendations.
4. release-please is configured to cut `1.0.0` (or it is tagged manually).
5. A fresh `composer create-project haddowg/json-api` install runs the
   getting-started example.
