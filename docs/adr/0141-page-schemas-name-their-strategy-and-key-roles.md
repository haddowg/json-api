# Page schemas name their strategy and key roles

A code generator had to guess a collection's pagination strategy from its `page` member names. Any key
can be renamed, and `kind()` too, so a renamed fixed or page paginator came out as unknown, and a
MultiPaginator menu gave no way to tell which strategy a request without `page[kind]` gets. Each
built-in paginator now stamps its page schema with `x-paginator: {strategy, keys}`, mapping each role to
its configured key, and a menu stamps `{strategy: "multi", default}`. The VO emits it statically, like
the cursor `x-profile` marker, so a custom paginator opts in by stamping the same shape. This is a new
vendor extension, so the generator contract moves to 3.
