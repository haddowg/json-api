# Derived from woohoolabs/yin, not forked

This library ports and modernises substantial parts of [woohoolabs/yin](https://github.com/woohoolabs/yin) (MIT) but does not track it upstream or keep its public API, because that freedom is what allowed the redesigned surface (typed exceptions, immutable value objects, a fluent resource layer). yin is always credited as the original, and the package is never called a "fork".

## Consequences

Fixes landed in yin are not inherited and must be re-derived by hand; divergence from yin's API is expected, not a defect.
