# relatedQuery is documented wherever the runtime reads it, with each relationship's vocabulary

[ADR 0050](0050-the-openapi-projection-is-registration-aware.md) documented `relatedQuery` only on a type's collection and single reads, through one generic component, while the adapters read it on every response rendering resources of one type: a create or update answering with its resource, a related read over one type and an action answering with a resource as well. So the error catalogue listed the sort and filter codes it raises on operations that did not document the parameter. One `OperationFacts` fact now decides both: the operation renders resources of exactly one type, and that type has a relation over one type that is not write-only, which the adapters address by name. Each such type gets a `<Type>RelatedQuery` component listing those relations, a to-many with its related endpoint's sort and filter vocabulary and a to-one with its filters only, and the sort and filter codes follow the vocabulary of the relations it addresses.

## Considered options

- Referencing the generic component from more operations: a reader could not tell which relationships a request may address, or by what, and the codes could not follow the vocabulary.
- An inline parameter per operation: a type's writes, reads and actions would repeat the same schema, which depends only on the rendered type.
- Keying the parameter by include path: the adapters reject a dotted path, and accept a relation that cannot be included.
