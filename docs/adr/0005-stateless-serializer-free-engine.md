# Stateless, serializer-free serialization engine

The serialization engine transforms domain objects into PHP **arrays** and holds
no per-pass state on the serializers or documents — request/object/result state
lives only on short-lived transformation objects. One serializer instance
therefore safely serializes many objects (collection items and recursively
included resources alike); there is no `initialize`/`clear` lifecycle to drive.

JSON encoding is deliberately *not* the engine's job: it returns arrays and the
response layer encodes them. This keeps serializers pure and reusable, and keeps
encoding options (and `JSON_THROW_ON_ERROR`) in one place at the response boundary.
