# Lifecycle decision-points are public, stateless seams reusable without the PSR-15 middleware

The throwable → generic-500 `Error` mapping (private to `ErrorHandlerMiddleware`)
and the HTTP-method × `Target`-shape choice of operation (private to
`Psr7ToOperationHandlerAdapter`) both produce spec-sensitive output, yet an
integration driving the lifecycle by other means — Symfony kernel listeners on
`Server::dispatch()`, per
[ADR 0013](0013-resource-registry-resolves-through-an-injectable-container.md) —
could only re-implement them and drift. Each is now a public, stateless seam:
`Schema\Error\InternalServerError::for(\Throwable, bool $debug): Error` and
`Operation\OperationFactory::fromRequest(JsonApiRequestInterface, Target, OperationContext): JsonApiOperationInterface`.
Each owns only its translation — `InternalServerError` never logs, derives a
status, or builds a response; `OperationFactory` never wraps the request or invents
a `Target` — and the middleware and adapter delegate to them with byte-identical,
characterization-tested behaviour.

The lifecycle's decisions are thus reusable without instantiating any `Middleware\*`
class, so an integration renders errors and builds operations identically to core
rather than approximately. Both are 1.0 public surface, extending the
framework-agnostic stance of
[ADR 0002](0002-framework-agnostic-on-psr-standards.md) beneath the PSR-15 path of
[ADR 0010](0010-server-is-immutable-per-version-root-and-psr15-handler.md).
