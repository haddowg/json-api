# Per-operation OpenAPI response declarations

- **Status:** accepted

A CRUD/read operation's projected OpenAPI success responses are no longer hard-coded
to a single status. `TypeMetadataInterface::responsesFor(OperationType)` returns a
declared set of `OperationResponseInterface` value objects. The carriers are atomic,
`new`-constructable classes — `Created` (201), `Ok` (200), `NoContent` (204),
`Accepted($jobType)` (202), `SeeOther` (303) and `MetaResult` (200 meta-only) — each
implementing the per-operation marker interfaces (`CreateResponse`, `UpdateResponse`,
`DeleteResponse`, `FetchOneResponse`, `FetchCollectionResponse`) for the operations it
is valid on, so an illegal `(operation, response)` pair is rejected by the type system
where possible and by `OperationResponses::validate()` otherwise. This lets a
type advertise `202 Accepted` (the async write from ADR 0125, carrying the pollable
job type's document), `204 No Content` on create (a client-generated id) or update,
`200` with a meta-only document on delete, and `303 See Other` on fetch-one (the
async-completion redirect) — the OpenAPI half of the JSON:API *Asynchronous
Processing* recommendation. `OperationResponses` owns the per-operation defaults and
set validation (no duplicate codes, at most one `202`, codes within the spec-valid
set) so every integration resolves and validates identically. The runtime companion
of a fetch-one `303` is the new `Resource\ResolvesCompletionRedirect` seam, which an
integration's fetch-one handler consults to emit a `SeeOtherResponse`.

**Why.** The projector previously emitted exactly one success response per operation,
so an author could implement the ADR 0125 async lifecycle but never *document* it — a
generated client had no way to know a `POST` might answer `202`, or that polling a job
resource could `303`. Modelling the choice as atomic, `new`-constructable response
objects composed in per-operation arrays (rather than raw status-code arrays, or static
factories) keeps the valid set self-validating — through per-operation marker
interfaces plus `OperationResponses::validate()` — and, crucially, is declarable in an
`#[AsJsonApiResource]` attribute argument, where PHP forbids static method calls but
permits `new`. It keeps the wire contract honest across the Symfony and Laravel
integrations, which populate the same core metadata and are diffed for byte-identity.

## Consequences

`TypeMetadataInterface` gains a `responsesFor()` method — a **breaking** addition to
the contract, taken pre-1.0 (the integrations are the only implementers and move in
lockstep; the change lands ahead of the 1.0 tag). A type that declares no override
returns the operation's single default, so its projected document is byte-identical to
before — the feature is opt-in and inert until a response set is declared.
