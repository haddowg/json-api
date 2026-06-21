---
status: superseded by ADR-0086
---

# Atomic Operations deferred, but its seams are already in place

> **Superseded by [ADR-0086](0086-atomic-operations-framework-agnostic-core-foundation.md):**
> Atomic Operations is now folded **into** 1.0; the deferred seams below — cross-document
> `lid` resolution and extension dispatch — are being built.

The JSON:API Atomic Operations extension is out of scope for 1.0. The design,
however, leaves the seams it will need: local IDs (`lid`) are modelled on resource
identifiers and flow through hydration, and `ext` media-type parameters are
parsed, negotiated, and validated against a server's supported set.

What is deliberately **not** built is cross-document `lid` *resolution* (mapping a
`lid` to a freshly-created resource within one request) and extension *dispatch* —
both arrive with Atomic Operations. Recording this stops the seams from being read
as dead code, and stops `lid`/`ext` from being "finished" piecemeal outside the
extension that gives them meaning.
