# Built links are link objects described by their OpenAPI operation

The links the server builds (by-convention resource and relationship links, the top-level `self`, pagination links and contributed links) render as JSON:API 1.1 link objects by default, so each can carry a `describedby` naming the OpenAPI operation that describes its target as the served document's URL plus a JSON Pointer fragment such as `#/paths/~1albums~1%7Bid%7D/get`. The URL comes from an adapter-supplied `DescribedByResolverInterface`, since only the adapter knows where the document is served, and `SpecDescribedByResolver` checks each operation against the document's own paths while the fragment is built from `PathTemplate`, the helper the projector keys `paths` with, so a link never names a fragment that isn't there and the two can't drift. A server can opt back out to strings with `withLinkObjects(false)`, and links an author supplies keep the shape the author gave them.

## Considered options

- Keep strings and add `describedby` only to the top-level document. A string can't carry a `describedby`, and the spec already requires clients to accept link objects, which the projected schema always allowed.
- Have core build the fragment from the type metadata alone. Core can't know at render time whether the adapter exposes an operation, so a resolver built from the projected document is the only way to omit a `describedby` for an operation that isn't served.
- Record the operation on every response unconditionally. `Server::dispatch()` returned the handler's response untouched, and it still does when no resolver is configured; the operation is only stamped when something will read it.

## Consequences

Error documents keep their links as they were and get no top-level `describedby`, since they describe a failure rather than the operation. An Atomic Operations result document is described by the atomic endpoint's `POST` only when the integration passes that endpoint's path to `AtomicOperationsOperation`, because the runtime can't derive it (the path lives in the OpenAPI metadata, which the server never sees); the resource objects inside it follow the server's link settings. A relation whose `withUriFieldName()` differs from its name links to a path the projector doesn't key, so its links carry no `describedby`.

An author's top-level `describedby`, from `withDescribedby()` or inside `withLinks()`, beats the per-operation one. That keeps an explicit choice explicit, but an integration that used `withDescribedby()` to point every document at the whole OpenAPI document hides the per-operation pointer until it drops that call.
