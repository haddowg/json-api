# Error-document status reflects a uniform error set, and a typed exception supplies its own

`AbstractErrorDocument::getStatusCode()` rounded *every* multi-error document to
the nearest status class, so a bag of validation errors that all share status
`422` collapsed to `400` — wrong for the common case where an adapter reports
several field violations at once. It now returns the shared status verbatim when
every error carries the same one, and only falls back to the rounding heuristic
for a genuinely mixed set. Separately, `ErrorResponse::fromException()` now
threads the exception's declared `getStatusCode()` as the document's explicit
status, so a typed `JsonApiExceptionInterface` (e.g. an adapter's 422
validation-failed exception) renders with the status it declares rather than one
re-derived from its error objects. Both were surfaced building the
`haddowg/json-api-symfony` Validator bridge, whose multi-violation `422`s
otherwise mis-statused as `400`.
