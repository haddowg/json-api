# Pagination is a server-composed strategy menu selected by `page[kind]`

A `MultiPaginator` composes several strategies and the client selects one with `page[kind]`, each paginator naming its own free-form `kind()`, so the author controls the menu and a custom paginator need not masquerade as a built-in. A key unique to one strategy selects it without `page[kind]` (so bare cursor-profile parameters keep working), a shared key requires it, and an absent `page` gets the declared default. `page` projects as one `deepObject` parameter whose `oneOf` branches encode the selection rule, and each paginator self-describes its page schema and stamps it with `x-paginator` (strategy and key roles), so a generator never infers the strategy from renamed keys.

## Considered options

- Key inference as the primary selector: shared `page[size]`/`page[number]` keys cannot be disambiguated by keys alone.
- A closed `kind` enum, and one projected parameter per bracketed key: the first forces custom paginators into built-in shapes, the second cannot express mutual exclusion outside prose.
