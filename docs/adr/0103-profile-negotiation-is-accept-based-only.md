# Profile negotiation is Accept-based only

Profiles are negotiated exclusively via the `Accept` (and `Content-Type`) `profile`
media-type parameter. The RC-era `?profile=` query parameter no longer negotiates a
profile: it is still tolerated on the request (it stays in the reserved query-parameter
allow-list, so `?profile=` is not a strict-validation `400`) but it neither drives a
profile's `finalizeDocument()`/schema contribution nor is advertised as applied.

Final JSON:API 1.1 dropped the query-parameter channel that the earlier release
candidates carried; negotiation is Accept-based. Previously `AbstractResponse::appliedProfiles()`
and `ProfileSchemaCollector` iterated `[...getRequestedProfiles(), ...getRequiredProfiles()]`,
so a `?profile=` request would negotiate, run the profile hook, and advertise the URI in
`jsonapi.profile` and the `Content-Type` — an advertisement the behavioural gates
elsewhere (which read `Accept` only) did not honour. That split was the bug: the document
claimed a profile it had not consistently applied. Reading `getRequestedProfiles()` (the
`Accept` channel) alone makes the advertised profiles match the applied behaviour.

`JsonApiRequest::getRequiredProfiles()`/`isProfileRequired()` are kept and marked
`@deprecated` for backward compatibility — the query-param channel is inert, not removed.
This is a small, spec-aligning change taken before the 1.0 freeze.
