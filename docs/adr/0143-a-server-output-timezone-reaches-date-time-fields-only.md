# A server's output timezone reaches `DateTime` fields only, through the resource's resolver

An app that stores local times but must answer in UTC had to write an `extractUsing()` closure per field,
because `useTimezone()` only converted on hydration. `DateTime` now takes `outputTimezone()` (with
`inputTimezone()` as the new name for the hydrate side) and `Server::withOutputTimezone()` sets a
default. The default skips `Date` and `Time`: a calendar date stored as local midnight lands on the
previous day in UTC, and a time of day has no date to convert against, so those convert only when the
field asks.

The default travels from the server to the registry, which resources already hold as their injected
resolver. `AbstractResource::getAttributes()` reads it through the new opt-in
`ProvidesOutputTimezoneInterface` and swaps each `AcceptsDefaultOutputTimezoneInterface` field for a
zoned copy before rendering. `Map`, `Obj` and `OneOf` pass it to their children. That keeps
`FieldInterface::serialize()` and `SerializerResolverInterface` unchanged.

## Considered options

- A method on `SerializerResolverInterface`. It is the natural home, but the Symfony bundle and any host
  implement that interface, so adding a method is a breaking change.
- A request attribute stamped by the server. Requests reach the render path from several places, and the
  only request attribute core reads is the operation `Target`. A hidden second one was not worth it.
- Converting inside the transformer. By then the field has already formatted the value as a string.

## Consequences

A resolver that wraps the registry, such as the bundle's pivot-substituting resolver, must implement
`ProvidesOutputTimezoneInterface` and delegate it, or the resources it serves render without the
default. Pivot meta and hand-written serializers format their own values and are not affected. The
OpenAPI document does not change for an RFC 3339 format, because the format still writes the same
shape in another zone.
