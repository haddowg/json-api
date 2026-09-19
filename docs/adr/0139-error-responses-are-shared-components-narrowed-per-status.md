# Error responses are shared components, narrowed per status

Every operation inlined a full Response Object for each error status it advertises — 382
of them in the witness document, 68 KB, a third of the file, across 15 distinct
(status, description) pairs. Each one is now written once into `components.responses` and
`$ref`'d from the operations, and each points at an `ErrorDocument<status>` whose
`errors.items.anyOf` offers only the codes the catalogue pins to that status. A `415` used
to advertise all 53 catalogued codes when exactly one can occur.

**Nine components, not fifteen.** Six of the fifteen pairs are the atomic batch phrasing a
status in terms of an operation within it ("an operation targets a resource that does not
exist") rather than the request as a whole. Fifteen components would name six of them after
a single endpoint, and leave `NotFound` and `AtomicNotFound` differing only in wording. So
the components are named for the status itself, in its HTTP reason phrase — `BadRequest`,
`NotFound`, `UnsupportedMediaType` — and the batch carries its own wording as a Reference
Object `description`, which OAS 3.1 permits and this document is 3.1. The three statuses it
phrases identically to every other endpoint reference the component bare, so an override is
always a real difference and never a restatement. A tool that ignores the override reads the
status's general description, which is true, just less specific than it could be.

**Narrowing does not close the list.** Each narrowed document keeps the open generic `Error`
branch first, exactly as the generic `ErrorDocument` does, for the reason
[ADR 0136](0136-the-projected-error-code-catalogue-is-open.md) gives: an application throws
codes the projector never saw. Two cases make that concrete rather than theoretical. A status
the catalogue claims no code for keeps the generic `ErrorDocument` — `401` always, since no
descriptor declares it, and any status whose codes a server's feature set gated out (every
`403` code core catalogues needs a write surface, so a read-only server narrows nothing at
that status). And [ADR 0018](0018-error-document-status-reflects-a-uniform-error-set.md)
takes a document of mixed statuses down to the status class they share, so a `400` body can
legitimately carry an error object whose own `status` reads `"422"`; the generic branch
absorbs it and validation still passes. Narrowing sharpens the vocabulary a status publishes.
It is not a promise about what that status can contain.

## Consequences

The witness document falls from 190 KB to 152 KB compact, a fifth of it, and the same 53
codes now reach a generator grouped by the status that can deliver them.

A consumer that reads `responses[status]` by value and never resolves a Reference Object now
finds no `content` where it used to. That is the cost of the change and it is worth naming:
`components.responses` is the mechanism OpenAPI provides for exactly this, and a reader that
cannot follow a `$ref` into it cannot read `components.schemas` either, which the document has
always depended on.

## Considered options

- **Fifteen named responses, one per (status, description) pair.** Rejected above: six of
  them belong to one endpoint, and the pairs that differ differ only in wording.
- **Numbering the components (`Error400`) instead of naming them.** The reason phrase is
  already the name of the thing, it is stable, and it reads as a type name in generated code.
  The narrowed *schemas* keep the number (`ErrorDocument400`) because they are variants of a
  named base and `InternalServerErrorErrorDocument` is what the other convention produces.
- **Leaving the bodies inline and accepting the size.** The duplication is not only bytes: 382
  copies of the same object is 382 places for the wording to drift.
