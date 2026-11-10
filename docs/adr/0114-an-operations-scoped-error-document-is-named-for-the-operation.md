# An operation's scoped error document is named for the operation, not its codes

An operation raising scoped codes at a status answered with a response and document named by joining every scoped code, such as `ErrorDocument409WithBindingDetachedDriftLockedGroupHasItems`, shared by the operations raising the same set. Adding a code renamed the component, so a generated client imported a different type after an additive change, and names grew with every code. Each such operation and status now gets its own document named from its type and operation, `CampaignTemplateBindingsUpdate409`, whose contents change with its codes and whose name never does. Its response sits inline in the operation, so the documents are the only per-operation names; the document references the shared shapes and envelope members rather than copying them, and leaves the open-list explanation to the shared `ErrorDocument<status>`. A name clashing with another operation's or another component fails the projection.

## Considered options

- Keep the code-joined names and share them: smallest document, but every additive scope change renames a type consumers import, which is the instability this replaces.
- A per-operation response component beside the document: a second set of per-operation names that adds nothing a generator needs, since the document carries the type.
- Naming by `operationId`: the ids use dots and lower case, so they need the same PascalCase mapping anyway, and putting the type first keeps a type's documents together.

## Consequences

The document grows with the number of scoped operation and status pairs rather than with the number of distinct scoped sets, a few hundred bytes each with its inline response and its example references. That is the price of names that survive a code being added.
