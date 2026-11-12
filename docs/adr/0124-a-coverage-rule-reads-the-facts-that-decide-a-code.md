# A coverage rule reads the facts that decide a code, and a missing fact offers it

This refines [ADR 0123](0123-each-core-error-code-is-offered-only-by-the-operations-whose-runtime-raises-it.md), which offered a code wherever the metadata could not tell, so a `readOnly(when:)` predicate, a relation's `cannotAdd(when:)`, an encoder, the include safeguards or the adapter's security layer each left a code on operations that never raise it. A rule now reads each fact that decides a code: from the field and relation definitions a type already reports, through `ConditionalFieldInterface` and `ConditionalRelationInterface`, wherever core holds them, and otherwise from a small opt-in `Declares*` interface the adapter implements on its server, type or relation metadata. A fact nobody states offers the code as before, so metadata from an older adapter documents what it did.

## Considered options

- One adapter interface answering every fact: an adapter could not state one fact without guessing at the others, and a server-wide answer would hide per-type and per-relation ones.
- Asking adapters for the facts core can read itself, such as a relation's predicates: two places would answer one question, and they could disagree.
- Letting core decide the security codes from the operation's declared security requirement: core cannot see a firewall or a middleware, so only the adapter knows which operations can be refused.

## Consequences

`INCLUSION_UNSUPPORTED` is the one code a missing fact leaves out, because nothing in core or either adapter raises it. A relationship mutation no longer reads `sort` or `filter`, so it offers neither code. When an adapter says its security layer can refuse every operation, the security codes stay in the shared shapes.
