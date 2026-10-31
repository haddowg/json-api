# A server's output timezone reaches `DateTime` fields only, through the resource's resolver

`Server::withOutputTimezone()` sets a default zone that `DateTime` fields render in, but `Date` and `Time` convert only when the field asks: a calendar date stored as local midnight would land on the previous day, and a time of day has no date to convert against. The default reaches resources through the registry they already hold, via the opt-in `ProvidesOutputTimezoneInterface`, leaving `FieldInterface::serialize()` and `SerializerResolverInterface` unchanged.

## Considered options

- A method on `SerializerResolverInterface`: hosts implement it, so adding a method breaks them.
- A request attribute stamped by the server, or converting in the transformer after the field has already formatted the value.

## Consequences

A resolver that wraps the registry must implement and delegate `ProvidesOutputTimezoneInterface`, or the resources it serves render without the default.
