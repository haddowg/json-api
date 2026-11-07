# A code is offered only by the operations declared to raise it

An application's own error codes are often specific to an endpoint, but every operation's error response for a status offered every code at it, so a client saw a payment code on a read it can never get. A type now declares the codes some of its operations raise, and the projection narrows those operations' responses: scope joins status, `source` and `meta` in grouping codes into shapes, operations raising the same scoped codes at a status share one response named for the status and those codes, and an operation gains a status a scoped code needs. Each code's example becomes one `components.examples` entry that every offering response references, since a narrowed response copying its codes' examples would grow the document with every scope.

## Considered options

- One response per operation: exact, but most operations raise the same codes, so it multiplies identical components.
- Narrowing the schema on the operation's `$ref`: a Reference Object can override only `summary` and `description`, never the schema.
- Scoping core's own codes by operation kind as well: every response would narrow and the component count would multiply, for codes a reader already expects on any write or read.
