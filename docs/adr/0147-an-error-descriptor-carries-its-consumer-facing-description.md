# An error descriptor carries its consumer-facing description

A projected `<Code>Error` component said what a code was (`code`, `status`, `title`) but
not what it meant or what a client should do next, so applications wrote that guidance
into each error's default `detail`, where a resolver replaces it per locale and no
generator can find it. `ErrorDescriptor` now takes an optional `description`, projected as
the component's `description`, and an optional `exampleDetail`, projected as an `example`
on its `detail` property. Both are optional trailing arguments, so every existing
descriptor keeps projecting exactly as before.

## Consequences

The contract moves to 4. The guard strips `description` strings before comparing, so a
description alone would not move it, but a `detail` property carrying an `example` is new
structure. `detail` stays out of `required`: the example illustrates the member and
promises nothing.
