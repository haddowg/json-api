# Derived from woohoolabs/yin, not forked

This library was built by porting and modernising substantial portions of
[woohoolabs/yin](https://github.com/woohoolabs/yin) (MIT) rather than forking it:
there is no upstream tracking relationship and no commitment to yin's public API.
That freedom is the whole point — it let us redesign the surface (typed
exceptions, immutable value objects, a fluent schema layer) instead of inheriting
yin's. yin is credited as the original work, and this package is never described
as a "fork".

## Consequences

- We do **not** receive yin's upstream fixes automatically; any spec or security
  fix landed there must be re-derived by hand.
- We owe no API compatibility to yin, so divergence is expected, not a defect.
