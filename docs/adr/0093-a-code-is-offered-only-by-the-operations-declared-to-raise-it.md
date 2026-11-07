# A code is offered only by the operations declared to raise it

An application's own error codes are often specific to an endpoint, but every operation's error response for a status offered every code at it, so a client saw a payment code on a read it can never get. An error now declares the operations it can arrive on with an `ErrorScope` on its descriptor, by type and operation name or by operation kind, and a `ScopedErrorSource` scopes an error the application does not own. The projection narrows those operations' responses: scope joins status, `source` and `meta` in grouping codes into shapes, operations raising the same scoped codes at a status share one response named for the status and those codes, and an operation gains a status a scoped code needs. Each code's example becomes one `components.examples` entry that every offering response references, since a narrowed response copying its codes' examples would grow the document with every scope.

## Considered options

- Declaring the codes on the type, per operation: it puts what an error does far from the error, needs every adapter to carry another attribute through, and conflicts with discovery, which catalogues an error for every operation wherever it finds it.
- Inferring the scope from `@throws` on the hooks and handlers serving each operation: precise where it reaches, but listeners and middleware run outside any one operation, so it cannot be the only source.
- One response per operation: exact, but most operations raise the same codes, so it multiplies identical components.
- Narrowing the schema on the operation's `$ref`: a Reference Object can override only `summary` and `description`, never the schema.
