# Custom (non-CRUD) actions are a first-class core operation

Author-defined, non-CRUD endpoints (Laravel-JSON:API-style "custom actions") dispatch
through a new `CustomActionOperation` implementing `JsonApiOperationInterface`, rather
than a new dispatch path or a verb-tunnelled CRUD operation. Because `Server::dispatch()`
already routes *any* `JsonApiOperationInterface` through strict-query validation, the
request-wide serving/authz gate, and the single `OperationHandlerInterface::handle()`
contract, modelling an action as a distinct operation lets it inherit all of that for
free with **no `dispatch()` change** — the operation carries the named `action`, the
HTTP `method`, and an optional request `body` (nullable, since input-less and raw-bodied
actions have no JSON:API document), and the framework integration constructs it (core's
`OperationFactory` stays CRUD-pure).

A raw/multipart action input is, by definition, not `application/vnd.api+json`, so
`RequestValidator::negotiate()` gains a trailing `bool $requireJsonApiContentType = true`
that, when `false`, skips **only** the request-body Content-Type assertion while keeping
Accept negotiation and extension support identical; the default preserves every existing
caller's behaviour. This is the minimal seam that lets an action accept a non-JSON:API
request body while still negotiating a JSON:API response.
