# A projected constraint holds for every value its schema describes

Code generators narrow types from the projected keywords, so a keyword that some valid value breaks produces a client that rejects real data. The read schema now takes a constraint only when every write that can set the field enforces it, where it used to share the update context, and `null` enters it when any such write accepts it. A `Required` field also projects its non-empty half (`minLength`, `minItems` or `minProperties` of 1), and a conditional `when()` rule never projects as a keyword, although a conditional `nullable()` still widens the type. [ADR 0156](0156-a-read-attribute-admits-null-unless-the-server-guarantees-a-value.md) adds the `null` a response carries when no write set the value.

## Considered options

- Keep reads on the update context. An update-only bound then appeared in responses that a create had written outside it, and a create-only `nullable()` was missing from them.
- Project a required boolean as `const: true`. The bundle's `NotBlank` does reject `false`, but `Required` means presence and non-emptiness, and calling `false` empty is a Symfony detail. The boolean stays unnarrowed.
