# The legacy hydrator family sources ids through hooks, not the Id-field policy

The `Id`-field SOURCE/POLICY model (ADR 0048) governs only `AbstractResource`. The
older hand-written hydrator family — `AbstractHydrator` / `AbstractCreateHydrator`,
both composing `CreateHydratorTrait` — sources the create id through its abstract
`generateId()` / `setId()` / `validateClientGeneratedId()` hooks instead, and a
review flagged the divergence: a subclass of this family does not read the new policy.

We keep the two paths **deliberately separate** rather than retrofit the policy model
onto the trait. The trait family is a lower-level, field-inventory-free escape hatch
(the bundle's `PlaylistHydrator` example uses it to fan one `title` member out to a
derived `slug`); it has no `Id` field to read a policy from, and `generateId()` is
**abstract** — so it never auto-mints a UUID silently, the subclass must implement it.
A subclass expresses the same choices through the hooks: a UUID id mints one in
`generateId()`, a store-provided id leaves `setId()` a no-op so the persister/DB
assigns it, a required client id throws from `validateClientGeneratedId()` on an empty
id. We documented this contract on the trait and pinned it with a test
(`CreateHydratorTraitTest`) — including the store-provided-via-`setId()`-no-op case —
so the declarative (`AbstractResource`) and hook-based (`CreateHydratorTrait`) create
paths cannot drift unnoticed.
