# The serve-time OpenAPI helpers live in core

Pruning a decorated document, the serialized serve artifact and its version fingerprint, and the ETag recipe live in `OpenApi\Serve` rather than in each adapter, so the Symfony and Laravel integrations prune and tag a document identically and a fix lands once. They take `stdClass` trees and strings, never HTTP messages, so the decorator interfaces (which take the framework's request), the controllers, the wiring and the cache location stay in the adapters.

## Considered options

- Hydrate the document from its JSON instead of PHP-serializing value objects. That needs a second, hand-maintained mirror of every value object's serialization, run on every request.
- Unserialize without an allow-list. The codec allows only the OpenAPI value objects, `stdClass`, enums found at encode time and classes the adapter names; any other object in a document makes `encode()` throw, so a tampered cache file can't instantiate an arbitrary class.

## Consequences

An artifact records the installed version and source reference of core and of any package the adapter names, and reads as missing once they differ. Unserializing into classes whose shape changed can leave properties uninitialized instead of failing, so a stale artifact must never be trusted. Changing the pruning rules or the ETag formula changes every adapter at once.
