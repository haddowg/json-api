# Localizable error catalogue via a code-keyed message resolver

The 52 typed exceptions hard-coded their `title`/`detail` and the render invariant
blocked any override, so the catalogue was neither overridable nor localizable
despite being advertised as both. We make each error carry a `context` of
placeholder values, turn `title`/`detail` into PSR-3 `{placeholder}` templates, and
add an optional `ErrorMessageResolver` (`title(code)` / `detail(code)` →
`?string` template) that supplies a replacement template per stable error `code`.

Core is the **sole interpolator** — resolve the template (via the resolver, or the
exception's inline default), then fill `context` — applied **uniformly** to every
error in a response, so an integration binds only a thin adapter over its framework
translator and locale negotiation stays the framework's job, not core's (the 422
`title` an integration emits localizes through the same seam for free). The
two-method, copy-only interface means `code`/`status` are **never** overridable
(they remain the machine and HTTP contract); an unfilled placeholder is left
literal so the render path never throws; and with no resolver bound the output is
**byte-identical**, which the existing error tests enforce. Chosen over injecting a
translator into each exception, a central core message table, and integration-only
override hooks.
