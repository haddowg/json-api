---
status: accepted
---

# Atomic Operations: a framework-agnostic core foundation, executed by the integration

The Atomic Operations extension (`ext="https://jsonapi.org/ext/atomic"`) is folded into
1.0 rather than deferred (superseding ADR-0011). Core owns the framework-agnostic
semantics — parsing an `atomic:operations` document into ordered `OperationDescriptor`s,
the all-or-nothing `AtomicLoop` over a four-method `AtomicLoopBackendInterface`
(`begin`/`commit`/`rollback`/`executeOne`), cross-operation `lid` resolution via a shared
`LocalIdRegistry`, and the `atomic:results` response (advertising the `ext` on the
`Content-Type` of both the success document and the rolled-back error document) — so the
extension's wire contract and conformance live next to the document model, and a future
non-Symfony integration reuses them. The integration (the Symfony bundle) owns only what
is framework-specific: the endpoint, request detection, and the backend that runs each
operation inside one transaction with deferred post-commit hooks.

The all-or-nothing loop is the load-bearing decision: a single `JsonApiException` from
any operation — or from the commit — rolls the batch back and renders one error document
whose `source.pointer` is prefixed with `/atomic:operations/<index>`; only a fully
successful batch commits. Per the extension spec a result object is limited to `data` and
`meta` (no `links`/`included`), and a `ref` must identify its target by `id` or `lid`.
