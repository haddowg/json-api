# Async processing has first-class `AcceptedResponse` / `SeeOtherResponse` affordances

- **Status:** accepted

A handler that accepts a write for asynchronous processing returns an
`AcceptedResponse` (`202 Accepted`) — carrying either a pollable job resource
(`forResource()`) or a meta-only status document (`fromMeta()`), with
`withContentLocation()` for the job URL and `withRetryAfter(int|\DateTimeInterface)`
for the poll hint. When the job finishes, a `GET` on that job URL returns a
`SeeOtherResponse` (`303 See Other`) whose `Location` points at the produced
resource.

**Why.** JSON:API 1.1's *Asynchronous Processing* recommendation defines this exact
`202`→`303` lifecycle, but the response vocabulary had no value object for either
status — an author had to hand-assemble a PSR-7 message with the right headers,
bypassing the render pipeline (profiles, `jsonapi`, encode options). These two VOs
make async a declarative response the same way `NoContentResponse` /
`DataResponse::fromResource()` make their cases declarative, and they render through
the shared `AbstractResponse` template so the body, content type and headers stay
consistent. `Retry-After` accepts an `int` (delta-seconds) or a `\DateTimeInterface`
(emitted as an RFC 7231 IMF-fixdate, normalised to GMT without mutating the value).

## Consequences

Both are framework-neutral response primitives — *when* to go async (queue a job,
represent it, expose its status endpoint) stays a framework-integration decision, so
the affordance is reusable across the Symfony bundle and the Laravel package without
either re-implementing the wire shape. Unlike `DataResponse`, `AcceptedResponse` does
not merge a top-level `self` — the write target is not the job resource's URI (the
job's `self` comes from its serializer; its polling URL is the `Content-Location`).
`SeeOtherResponse` is bodiless like `NoContentResponse`; the document-level members do
not apply, but `withHeader()` does.
