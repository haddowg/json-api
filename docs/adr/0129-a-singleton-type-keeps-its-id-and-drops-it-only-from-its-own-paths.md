# A singleton type keeps its id and drops it only from its own paths

A type with one resource per caller, such as an inbox, is served at `/{uriType}` while its resource object keeps its real id, so other types link to it and include it by `{type, id}` and its counts can arrive with a page of inbox items in one request. The type resolves the caller's id, once per request, and the adapters then run their ordinary fetch and update with it, so access scopes, query extensions, the `PATCH` body id check, lifecycle hooks and transactions apply unchanged rather than being reimplemented for a second, id-less read path. A singleton serves no collection, create or delete and only resource-scoped actions, and declaring them is an error that points at a second type over the same entity, since one type answering both `/inbox` and `/inbox/{id}` would give one id two self links.

## Consequences

Every link into a singleton's endpoints is `/{uriType}`, so its access scope must expose only the caller's row; that is left to the author. Its paths carry no `{id}` and its `<Type>Resource` names its path in `x-singleton`, structures only documents with a singleton use, so they form a scoped generator contract tier and every other document keeps its stamp.
