# A feature-gated code is offered only by the operations that can raise it

A code tied to an `ErrorFeature` was gated for the whole server and then offered in the shared shapes every operation carries, so one cursor-paged collection put `CURSOR_MALFORMED` on every delete, and a client's breaking-change check flagged every operation when a feature arrived. The server-wide gate stays, and each feature now also names the operations that can raise its codes, read from the same facts that decide an operation's parameters and request body, and puts its codes through the existing `ErrorScope` mechanism. Its shapes are grouped by feature rather than by the operations reached, so a shape keeps its name as those operations change and two features never share one by coincidence.

## Considered options

- Narrowing each code to its own operations, such as `CLIENT_GENERATED_ID_NOT_SUPPORTED` to creates only: more precise, but it means scoping inside a feature, which the descriptors do not say. A descriptor can still declare a narrower scope of its own, which replaces the feature's.
- Grouping feature codes by the operations they reach, as declared scopes are: shapes would merge and split, and be renamed, whenever two features happened to reach the same operations.
- Scoping `INSUFFICIENT_SCOPE` and `METHOD_NOT_ALLOWED` too: any operation can raise the first through `?include` or its own scopes, and the second answers a method no documented operation has, so both stay in the shared shapes.
