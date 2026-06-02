# Typed exceptions carry error data, not built documents

Errors are modelled as a typed exception hierarchy — a `JsonApiException`
contract exposing `getErrors(): list<Error>` and `getStatusCode(): int` —
replacing the exception-factory indirection of the original. An exception carries
only the error *data*; the serialization layer assembles the JSON:API error
document from it.

This keeps the exception layer decoupled from the request/response machinery
(body-invalid exceptions accept already-extracted data, never a PSR message) and
lets a single error-handling middleware render any thrown `JsonApiException`
uniformly into a spec-compliant error document.
