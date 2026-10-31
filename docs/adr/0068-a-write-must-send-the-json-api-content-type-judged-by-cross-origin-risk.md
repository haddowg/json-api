# A write must send the JSON:API Content-Type, judged by cross-origin risk

`ContentNegotiationMiddleware` requires `Content-Type: application/vnd.api+json` on every `POST` and on any other unsafe method that has a body, so a form or `text/plain` body never reaches parsing as if it were a document. A bodyless `POST` is not exempt, because `POST` is the one unsafe method a browser sends cross-origin without a preflight; a bodyless `PATCH`/`PUT`/`DELETE` is always preflighted and needs no `Content-Type`. Strictness is a flag on `negotiate()` rather than its default, because negotiation alone cannot tell a write from a read.

## Consequences

A raw-bodied action must name `text/plain`, `multipart/form-data` or `application/x-www-form-urlencoded` explicitly to accept them; a wildcard does not admit the preflight-free types.
