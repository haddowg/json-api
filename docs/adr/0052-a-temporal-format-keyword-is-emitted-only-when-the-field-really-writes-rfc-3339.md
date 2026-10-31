# A temporal `format` keyword is emitted only when the field really writes RFC 3339

`date-time`/`date`/`time` are RFC 3339 productions, so `DateTime::schemaFormat()` renders a fixed set of reference instants with the field's configured format and emits the keyword only if every one is RFC 3339 and parses back to the same instant; otherwise the field documents as a plain string with an example in the description. The projector and the validation `SchemaCompiler` both defer to it, so neither overclaims. `Time`'s default offsetless format therefore projects as a plain string.

## Considered options

- Adding or deriving a `pattern` from the PHP format: a false keyword stays in place for generators that read only it, and a regex inferred from samples is a universal claim the format string cannot back.
- An `x-` extension carrying the PHP format, or rejecting non-RFC-3339 formats as configuration errors.
