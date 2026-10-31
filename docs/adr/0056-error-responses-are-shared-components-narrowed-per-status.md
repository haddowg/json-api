# Error responses are shared components, narrowed per status

Each advertised error status is one `components.responses` entry, named by its HTTP reason phrase and `$ref`'d from operations, pointing at an `ErrorDocument<status>` that lists only the codes the catalogue pins to that status. A status no code claims keeps the generic `ErrorDocument`, and every narrowing keeps the open generic `Error` first: an application throws codes the projector never saw, and a document of mixed statuses takes the class they round to, so a `400` body may carry an error whose own `status` is `"422"`. Context-specific wording (the atomic batch) rides on the Reference Object's `description`.

## Considered options

One component per (status, description) pair, numbered component names, or inline bodies, which duplicated the same object hundreds of times.
