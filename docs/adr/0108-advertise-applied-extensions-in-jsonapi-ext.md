# Applied extensions are advertised in `jsonapi.ext`

A response produced under an applied JSON:API extension now records the extension URIs
in the top-level **`jsonapi.ext`** array, symmetric with `jsonapi.profile`
(`AbstractResponse::applyExtensions()`). Previously the applied extension was echoed only
in the `Content-Type` `ext` media-type parameter; the document did not self-describe it.

JSON:API 1.1 defines both `ext` and `profile` members on the `jsonapi` object for
advertising the applied extensions and profiles. The only response that applies an
extension today is the Atomic Operations response (the atomic URI, set via
`withExtensions()` / a subclass's `extensions()`), so its document now carries
`jsonapi.ext: ["https://jsonapi.org/ext/atomic"]` alongside the `Content-Type` parameter.
The extension set is resolved once (`resolvedExtensions()`) and reused for both the media
type and the `jsonapi.ext` member. A response applying no extension is unchanged. A small,
symmetric, spec-aligning change taken before the 1.0 freeze.
