# Atomic Operations

The [JSON:API Atomic Operations extension](https://jsonapi.org/ext/atomic/) lets a
client send a **batch of write operations in one request**, applied in order and
**all or nothing**: either every operation commits, or the first failure rolls the
whole batch back. This page describes the model the library supplies — the wire
contract, the framework-agnostic loop, and the seam an integration implements to
run it. It is conceptual; for the Symfony endpoint, configuration and a worked
example, see the
[bundle's Atomic Operations guide](https://haddowg.github.io/json-api-symfony/atomic-operations/).

## The extension at a glance

The extension is identified by a single canonical URI,
`https://jsonapi.org/ext/atomic`
([`AtomicExtension::URI`](../src/Atomic/AtomicExtension.php)), advertised in — and
matched against — the `ext` media-type parameter:

```
Content-Type: application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"
Accept:       application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"
```

It claims one reserved member-name prefix, `atomic`: the request document carries an
**`atomic:operations`** array, and a successful response carries an
**`atomic:results`** array. An integration exposes a **single endpoint** for the
batch (the spec's `POST /operations`), distinct from the per-type CRUD endpoints.

### The request

`atomic:operations` is an ordered, non-empty array of operation objects. Each carries:

- an **`op`** — `add`, `update`, or `remove`
  ([`AtomicOperationCode`](../src/Atomic/AtomicOperationCode.php)). `add` creates a
  resource or adds members to a to-many relationship; `update` replaces a resource
  or a relationship; `remove` deletes a resource or removes members from a to-many
  relationship. Whether an operation is a *resource* operation or a *relationship*
  operation is carried by its target's `relationship`, not by the code;
- a **target** — exactly one of a structural **`ref`**
  ([`Ref`](../src/Atomic/Ref.php): a `type`, exactly one of `id`/`lid`, and an
  optional `relationship`) or a **`href`** string (a URL the integration matches
  against its routes). A resource `add` may carry neither — its target is its own
  `data.type`;
- a **`data`** payload — a resource object (an `add`/`update` of a resource), a
  single resource-identifier or `null` (a to-one relationship `update`), or a list
  of resource-identifiers (a to-many relationship operation). A `remove` of a
  resource carries no `data`.

### The response

A fully successful batch is a `200 OK` document whose sole primary member is
**`atomic:results`** — an ordered array with one result object per operation, in
request order. Per the spec a result object carries only **`data`** and/or
**`meta`** (the created/updated resource or identifier); it is **never** allowed
`links` or `included`. An operation with nothing to return — a `remove`, or an
`update` the server fully applied with no body — contributes an **empty result
object** `{}`
([`AtomicResult`](../src/Atomic/AtomicResult.php),
[`AtomicResultsResponse`](../src/Response/AtomicResultsResponse.php)). The response
advertises the extension on its `Content-Type` `ext` parameter.

### Local ids (`lid`)

Because operations run in order, a later one can reference a resource an earlier one
created — before the server id exists — by a client-assigned **local id** (`lid`).
A create carries a `lid`; a later operation references it (in a `ref`, or in
relationship linkage), and the executor resolves it to the real, server-assigned id
once the create has run. The [`LocalIdRegistry`](../src/Atomic/LocalIdRegistry.php)
holds the `(type, lid)` → id map for the batch: a reference to an unregistered `lid`
is a [`LocalIdNotFound`](../src/Exception/LocalIdNotFound.php) (`400`), a duplicate
`(type, lid)` a [`LocalIdConflict`](../src/Exception/LocalIdConflict.php) (`400`).
References are **backward-only** — a `lid` must be registered by an earlier
operation in the same batch.

## Framework-agnostic by construction

The library owns the extension's framework- and storage-agnostic semantics; an
integration supplies only what is framework-specific (the endpoint, request
detection, transactions). Three pieces make up the core foundation:

1. **The parser.**
   [`AtomicOperationsParser`](../src/Atomic/AtomicOperationsParser.php) turns a
   decoded request document into an ordered list of
   [`OperationDescriptor`](../src/Atomic/OperationDescriptor.php)s. It is purely
   **structural**: it checks the `atomic:operations` array is present and non-empty,
   each operation has a known `op`, exactly one of `ref`/`href` (or neither, only for
   a resource `add`), a structurally valid `ref`, and a `data` shape appropriate to
   the code. Every failure is an
   [`AtomicOperationsInvalid`](../src/Exception/AtomicOperationsInvalid.php) (`400`)
   carrying a `source.pointer` to the offending member. **Semantic** validation —
   whether a `type` is registered, a relationship exists, a `lid` resolves — is *not*
   the parser's job; that is execution-time work, so the parser touches no registry
   or storage and runs in the library alone.

2. **The loop.** [`AtomicLoop`](../src/Atomic/AtomicLoop.php) is the all-or-nothing
   driver. Given the parsed descriptors and an
   [`AtomicLoopBackendInterface`](../src/Atomic/AtomicLoopBackendInterface.php), it
   opens the boundary (`begin()`), applies each operation in order (`executeOne()`,
   threading one shared `LocalIdRegistry`), then `commit()`s and returns an
   `AtomicResultsResponse`. The **first** operation — or the commit itself — that
   throws a `JsonApiExceptionInterface` triggers a `rollback()` and a single
   `ErrorResponse`, with each error's `source.pointer` prefixed by the failing
   operation's index (`/atomic:operations/<i>` + the inner pointer; an error with no
   pointer is located at `/atomic:operations/<i>`). Any other `\Throwable` rolls back
   and is left to propagate.

3. **The backend seam.** `AtomicLoopBackendInterface` is the four-method contract
   the integration implements — `begin()`, `executeOne()`, `commit()`, `rollback()`.
   The library knows nothing about transactions or persistence; the backend supplies
   them. This is what keeps the loop reusable: a Symfony bundle drives it over a
   Doctrine transaction; a different integration could drive it over anything else.

Both the success and the rolled-back error document advertise the extension on their
`Content-Type` (a document produced under an applied extension must declare it).

## Operations as an independent extension

The Atomic Operations extension defines exactly three operation codes — `add`,
`update`, `remove`. A natural question is whether you can add *another* operation
code to a batch — say, to invoke a custom action inside the same all-or-nothing
request. **You cannot do this by extending `ext=atomic`.**

A JSON:API extension is an immutable, versioned contract identified by its URI. The
set of operation codes, and the members the extension reserves under its `atomic:`
namespace, are part of that contract. Adding a new `op` value, or a new member, to
`ext=atomic` would change the meaning of that URI — so a document a client sent
under `ext="https://jsonapi.org/ext/atomic"` would no longer mean the same thing to
two servers. That is precisely what the extension mechanism exists to prevent.

The spec-correct way to add operation codes is therefore a **separate, independent
extension under its own `ext` URI** — which:

- **defines its own operation vocabulary** (its own `op` values and any reserved
  members) under its own namespace, leaving `ext=atomic`'s enum and members
  untouched;
- **is negotiated independently** — a request opts into it by listing its URI in the
  `ext` media-type parameter (alongside `ext=atomic` if it wants both);
- is **never a [profile](profiles.md)**. A profile may add *semantics* a processor
  is free to ignore (an unrecognised profile is harmless); it may **not** introduce
  new document members or change processing rules. New operation codes change how a
  document is processed, so they are extension territory, not profile territory.

In short: the operation set is closed for `ext=atomic`; new operations live in their
own extension with its own URI. The library's foundation supports this cleanly — the
parser, loop, and backend seam are all keyed on the `atomic` vocabulary, and a new
extension brings its own parser/loop or its own backend without mutating any of
them.

## See also

- The [bundle's Atomic Operations guide](https://haddowg.github.io/json-api-symfony/atomic-operations/)
  — the Symfony endpoint, configuration, transactions, lifecycle hooks, and a worked
  example.
- [Operations and dispatch](operations.md) — the CRUD operation VOs a batch's
  sub-operations are turned into.
- [Profiles](profiles.md) — the *other* extensibility mechanism, and why it is not
  the place for new operations.
