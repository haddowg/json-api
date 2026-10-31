# The serve-time OpenAPI helpers live in core

Pruning a decorated document, the serialized serve artifact with its version fingerprint, and the ETag recipe live in `OpenApi\Serve`, so the Symfony and Laravel integrations serve documents identically and a fix lands once; they take `stdClass` trees and strings, never HTTP messages, leaving controllers, decorators and caching in the adapters. The artifact is PHP-serialized value objects behind an unserialize allow-list, rather than rehydrated from JSON, which would need a hand-maintained mirror of every value object.

## Consequences

An artifact records the versions of core and named packages and reads as missing once they differ, since unserializing into changed classes can leave properties uninitialized instead of failing.
