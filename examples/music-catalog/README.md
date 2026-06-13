# Music catalog — the worked example app

A complete JSON:API 1.1 service built on `haddowg/json-api`, served entirely from
an **in-memory data layer**. It is the **single source of truth** for the library
documentation: every docs snippet is extracted from these files, and the whole app
is exercised by a CI-run test suite, so the docs can never drift from the code.

The catalog models artists, albums, tracks, playlists, users, favorites and
libraries — a relationship topology that exercises every relation type once
(`BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany` + pivot, `MorphTo`,
`MorphToMany`) — plus a standalone read-only `charts` type registered as a **bare
serializer with no Resource**, witnessing the capability-composition thesis.

## Layout

| Path | What lives there |
| --- | --- |
| `src/Domain/` | Plain mutable domain objects (no base class, no ORM). |
| `src/Data/` | `InMemoryStore` (shared read/write store), `InMemoryRepository` (fetch/create/update/delete + the pagination push-down loop), `CriteriaApplier` (composes the reference `ArrayFilterHandler` + `ArraySortHandler` and the custom filter/sort arms). |
| `src/Resource/` | The seven `AbstractResource` subclasses declaring fields, relations, filters and sorts. |
| `src/Serializer/`, `src/Hydrator/` | The custom `TrackSerializer`, `ChartSerializer` (bare) and `PlaylistHydrator` escape hatches. |
| `src/Filter/`, `src/Sort/` | A custom `WithinRadius` filter and a computed `TrackCountSort`. |
| `src/Http/`, `src/Handler/` | The `PathPrefixRouter` middleware and the `MusicCatalogHandler` (one `OperationHandlerInterface` over all nine operations). |
| `src/Profile/`, `src/Exception/` | A `TimestampProfile` and a custom `PaymentRequired` exception. |
| `src/bootstrap.php`, `src/Seed.php` | The single `bootstrap()` entry point and the deterministic seed fixtures. |
| `tests/` | The CI-run suite — one file per docs page. |

## Running the tests

The example shares the core `composer.json` `autoload-dev` and the core
`phpunit.xml.dist` test suite, so the whole repository's tests (including this app)
run with:

```bash
composer test
```

To run only the example app's tests:

```bash
vendor/bin/phpunit examples/music-catalog/tests
```

It depends only on the library plus a PSR-7/PSR-17 implementation
(`nyholm/psr7`, already a `require-dev` of core) — no database, no extra
dependencies.
