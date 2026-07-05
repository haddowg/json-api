# Public wire-value cast on the field contract

Adapters sometimes hydrate a wire value outside the resource hydration path — a
belongs-to-many pivot meta value written onto a join row, where only the pivot
field's declared type is in play — and the value cast lived solely inside the
protected `AbstractField::deserializeValue()`, forcing integrations into a
`Closure::bind` workaround. `FieldInterface` now declares
`castWireValue(mixed $value): mixed` (implemented on `AbstractField` by
delegating to `deserializeValue()`), exposing the declared type's cast as a
request-independent public entry point; the document-aware hydrate hooks
(`deserializeUsing`/`fillUsing`) are deliberately not consulted, because they
require the full resource data context that this seam by definition does not
have.
