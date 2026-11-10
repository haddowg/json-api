# A union holds one member per JSON type and projects one schema when that is exact

A `Union` takes at most one member per JSON type, an `Integer` and a `Decimal` counting as one, so a value's JSON type names the single member whose cast and constraints apply and whose PHP type comes back, with no discriminator and no first-match order to explain. That also makes a single schema exact: `type` lists the members' types and each member's keywords that only constrain its own type, such as `maxLength` or `minimum`, sit beside it. A member carrying a keyword that would constrain the other types too, such as `enum`, `const`, a `$ref` or a description, switches the projection to a `oneOf` of the members' schemas, with `null` as its own branch.

## Consequences

`ArrayList::element()` keeps returning only an `Obj` or `OneOf`, and a list of unions reports its union through `elementUnion()`, so an adapter that predates unions never receives a shape it cannot handle. A stored value no member takes is rendered unchanged, as a `OneOf` value selecting no variant is, rather than failing the response.
