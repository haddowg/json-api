# Operation examples are built from declared examples and never invented

Each operation's request, success responses and shared error responses carry a whole `example` assembled from the examples fields, relations and error descriptors declare, mirroring what the server renders. When any needed value has no declared example the operation gets no example and the projection records a `MissingExample` naming the operation and field. A type-derived placeholder such as `"string"` or `0` was rejected: it teaches a client a wrong value and nothing flags it, whereas a missing example is visible and fixable.

## Consequences

Facts the examples need but the schema does not carry (whether a paginator always counts, an id for linkage to an unregistered type) come from opt-in metadata rather than new schema structure. An override naming an operation the type does not expose fails the projection.
