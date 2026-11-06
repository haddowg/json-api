# An undiscriminated OneOf is selected by a required signature and written whole

A `OneOf` no longer defaults its discriminator to `type`, the member every resource object already uses for something else. Without a discriminator, each variant must declare a signature: a member no other variant declares that is required on every write and always rendered. The member the value carries selects the variant, and a variant with no signature is a build error. With closed branches, that makes the projected `oneOf` exact, since a value can match only the branch whose signature it carries. Every write replaces the whole value, because nothing else says which stored variant a partial `PATCH` should merge into, so an update branch requires what the variant requires.

## Considered options

- Matching a variant by whichever declared key set fits the value: a partial update sharing keys between variants fits several, so the server would guess, and a strict `oneOf` would reject requests the server accepted.
- Keeping partial merges by falling back to the stored variant: the update schema would have to become an `anyOf`, and a partial that happened to fit another variant would switch it silently.
