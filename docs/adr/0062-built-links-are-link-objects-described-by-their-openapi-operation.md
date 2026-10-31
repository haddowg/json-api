# Built links are link objects described by their OpenAPI operation

Links the server builds (convention resource and relationship links, top-level `self`, pagination and contributed links) render as JSON:API link objects by default, so each can carry a `describedby` pointing at the OpenAPI operation that describes its target. The document URL comes from an adapter-supplied resolver that checks each operation against the served document, and the JSON Pointer fragment is built by `PathTemplate`, the same helper that keys the projector's `paths`, so a link never names a missing fragment. Author-supplied link containers keep the shape the author gave them; `withLinkObjects(false)` opts a server back to strings.

## Considered options

- Strings with a top-level `describedby` only: a string cannot carry one, and clients must already accept link objects.
- Building fragments from type metadata alone: core cannot know whether the adapter actually serves an operation.
