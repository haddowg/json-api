# Accept negotiation 406s only when no media type conforms

The `Accept` parameter rule is split from the `Content-Type` rule. They are different
in the JSON:API 1.1 spec and were previously conflated through a single
`MediaType::isValid()`:

- **Content-Type** (`isValid()`, unchanged) — the single media type MUST carry only
  `ext`/`profile` parameters; any other parameter is a `415`.
- **Accept** (new `MediaType::accepts()`) — a `406` is required only when **every**
  `application/vnd.api+json` instance carries a forbidden media-type parameter. A
  single conforming instance makes the header acceptable, and the optional `q` weight
  (and any accept-extension parameters after it) are not media-type parameters and are
  ignored.

The old shared rule **over-rejected**: a spec-conformant `Accept` such as
`application/vnd.api+json; charset=utf-8, application/vnd.api+json` (one clean instance)
was wrongly `406`'d, and a bare `application/vnd.api+json;q=0.9` was rejected because
`q` was misread as a media-type parameter. That is a violation of a spec MUST on the
public negotiation contract, so it is fixed before the 1.0 freeze (and `JsonApiRequest::validateAcceptHeader()`
now routes through `accepts()` while `validateContentTypeHeader()` keeps `isValid()`).
