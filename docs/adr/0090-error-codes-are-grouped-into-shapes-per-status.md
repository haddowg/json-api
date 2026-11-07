# Error codes are grouped into shapes per status

The projected catalogue groups the codes at one status that fill the same `source` member and declare the same `meta` into one schema component, a *shape*, and each code is a `const` branch of the shape's `code` carrying the code's own `title` and `description`. Under one component per code, every new code added a branch to the error document's `anyOf`, which oasdiff reports as a breaking `response-property-any-of-added`. A code joining an existing shape adds a branch inside `code` instead, which oasdiff reports as a warning, and the per-code prose stays visible in rendered documentation. The `code` is an `anyOf` even with one branch, so adding a code is always one more branch and never changes the shape's form. Each code's example `detail`, `source` and `meta` move from the schema to a named entry in each error response's `examples`.

## Considered options

- An `enum` per status: a value of an `enum` cannot carry its own `title` and `description`, so the per-code guidance would leave the rendered document or move to an extension no viewer shows.
- `if`/`then` rules keyed on `code`: oasdiff misreports them as `allOf` removals and additions, and reports a breaking change to a member's type only as a warning.
- One example per status instead of per code: about half the size again, but most codes would have no example.
