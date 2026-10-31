# A bad filter value is a 400 on `source.parameter`, validated before the data layer

Filters declare value constraints from the existing constraint vocabulary, and an adapter validates a client-supplied value before it reaches the data layer, so a mistyped `filter[year]=banana` is a clean error instead of a silent non-match (loose stores) or a `500` (strict drivers). The error is `FilterValueInvalid`, a `400` with `source.parameter`, deliberately not a `422`, which is reserved for document semantic errors located by `source.pointer`.
