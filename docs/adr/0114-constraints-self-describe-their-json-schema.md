# Schema shape lives on the value object; execution lives in the adapter

A constraint contributes its own JSON Schema keyword (`ProvidesJsonSchema`), a structured filter describes its own query-parameter envelope (`DescribesQueryParameter`), and a composite field describes its own base schema (`ProvidesFieldSchema`). The OpenAPI projector and the validation `SchemaCompiler` reduce over these instead of keeping mirrored `instanceof` switches that drifted, and an application's own constraint, filter or field documents itself with no core change. JSON Schema and OpenAPI shapes are framework-neutral and belong on the object; validation and query execution are host-specific and stay in adapters.

## Considered options

- Moving every constraint onto the seam: `In`, the composites, date bounds and `When`/`CompareField` produce consumer-specific output and keep explicit arms, though composites recurse through the seam for their leaves.
- A filter building its whole value schema: it would couple every filter to the projector, so the projector builds the value schema and the filter only wraps it.
