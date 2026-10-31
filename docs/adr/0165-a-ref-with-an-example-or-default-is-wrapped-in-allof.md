# A `$ref` carrying an example or default is wrapped in a single-entry `allOf`

OAS 3.1 allows sibling keywords beside a `$ref`, but ReDoc and other tools reading OAS 3.0 semantics drop them, so `Schema` serialises a `$ref` that carries `example`, `examples` or `default` as `{"allOf": [{"$ref": …}], …}`. Doing it at serialisation means every projection path and decorator gets it without remembering to; code generators treat a single-entry `allOf` as the `$ref` itself.
