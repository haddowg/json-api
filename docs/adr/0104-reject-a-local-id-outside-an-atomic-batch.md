# A local id (`lid`) outside an Atomic Operations batch is a 400

A regular (non-atomic) request body carrying a local id (`lid`) — on the primary
resource object, on embedded relationship linkage, or on a relationship-endpoint
linkage body — is now rejected with a `400` ({@see \haddowg\JsonApi\Exception\LocalIdNotSupported},
code `LOCAL_ID_NOT_SUPPORTED`, `source.pointer` locating the offending `lid`) rather
than silently ignored.

The `lid` member is defined **only** by the Atomic Operations extension: it names a
resource created earlier in the same batch so a later operation can reference it. It
has no meaning in a standalone request — a resource must be identified by `id`. Before
this change core parsed a `lid` on a standalone create into `ResourceIdentifier` and
then dropped it (nothing consumes the primary resource's `lid` off the atomic path),
so a client that mistakenly sent `lid` got a `2xx` that had quietly done the wrong
thing. Rejecting it makes the mistake visible.

The check lives in `JsonApiRequest`: `validateTopLevelMembers()` walks `data` (the
primary object plus each embedded `relationships[*].data`), and the relationship-endpoint
linkage parsers (`getRelationshipDataToOne()`/`getRelationshipDataToMany()`) reject a
`lid` in the top-level linkage — the two chokepoints the non-atomic write paths share,
which the atomic path does not traverse (its `atomic:operations` document carries no
top-level `data`, and its local ids are resolved to real ids before the write handler
reads any operation body). A present-but-empty `lid` is treated as absent, mirroring
`ResourceIdentifier`'s "has lid" rule. Taken before the 1.0 freeze.
