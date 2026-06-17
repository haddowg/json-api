# Applied profiles are advertised in `jsonapi.profile`, not `links.profile`

A response that applies one or more profiles now records the applied URIs in the
top-level **`jsonapi.profile`** array (`AbstractResponse::applyProfiles()`), not in
a `links.profile` member. JSON:API 1.1 defines the `jsonapi` object's `profile`
member as the place applied profiles are listed; the base response schema permits
`jsonapi.profile` but rejects `profile` under top-level `links`
(`unevaluatedProperties: false`), so the previous `links.profile` advertisement made
any profile-applied document fail base-schema validation. The `Content-Type`
`profile` media-type parameter is unchanged. The testing helper
`JsonApiDocument::assertProfileApplied()` reads `jsonapi.profile` accordingly. This
surfaced while making `?withCount` a profile (ADR 0065): it was the first response
asserted spec-compliant while advertising a profile, but the fix applies to every
profile (cursor pagination, relationship queries) and is a small, correct change
taken pre-1.0.
