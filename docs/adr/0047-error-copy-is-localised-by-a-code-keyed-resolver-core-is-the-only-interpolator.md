# Error copy is localised through a code-keyed resolver; core is the only interpolator

Each error carries a `context` of placeholder values, its `title`/`detail` are `{placeholder}` templates, and an optional `ErrorMessageResolverInterface` supplies replacement templates per stable error `code`. Core resolves and interpolates uniformly for every error, so an integration binds only a thin adapter over its translator and locale negotiation stays the framework's job. `code` and `status` are never overridable (they are the client and HTTP contract), and an unfilled placeholder is left literal so rendering never throws.

## Considered options

Injecting a translator into each exception, a central core message table, and integration-only override hooks.
