# Responses carry a settable status, and a No Content response shape exists

Every response value object rendered a fixed status (a `DataResponse` was always
`200`), and none could render an empty body — so a write integration had no way to
return the `201 Created` a successful create requires, nor the `204 No Content` a
successful delete returns. `AbstractResponse` now has a `withStatus()` wither that
the `toPsrResponse()` template honours over the rendered default (a create handler
sets `201` and a `Location` header via the existing `withHeader()`), applied once
in the template so every response type benefits without per-subclass edits. A new
`NoContentResponse` renders a true `204`: `RenderedDocument` gained a `hasBody`
flag, and the template omits both the body and the `Content-Type` header when it
is `false`. `OperationHandlerInterface`'s return union admits the new type.
Surfaced building the `haddowg/json-api-symfony` write path, which had no
spec-compliant way to express either status through the response layer.
