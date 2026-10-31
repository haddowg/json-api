# The projected read schema never claims more than every response honours

Generators narrow types from projected keywords, so a keyword some real response breaks produces a client that rejects real data; understating costs a client one check. The read schema therefore takes a constraint only when every write that can set the field enforces it, admits `null` unless a value is guaranteed (create-required, a hook with a non-null return type, or the author's `guaranteed()`), and lists in `required` only the members (including nested children) rendered on every response. A pivot gets separate response and request schemas, because one shared shape could not say a read-only pivot field is always rendered without demanding it in every request.

## Considered options

- Treating read-only fields as guaranteed: a read-only `approvedAt` is null until something approves it, and core cannot tell it from `createdAt`.
- Marking read-only pivot fields `readOnly` in one schema: many generators ignore `readOnly` when building request types.
