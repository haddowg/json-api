# First-class top-level `describedby` link

JSON:API 1.1 names `describedby` as a top-level link (a link to a description
document for the document, e.g. an OpenAPI spec). It was only expressible through
`DocumentLinks`' arbitrary-links escape hatch, and the response value object had no
way to contribute it without reconstructing whatever links a handler had already set.

We make `describedby` a first-class named member of `DocumentLinks` (constructor
param + getter) for an author building links directly, and add
`AbstractResponse::withDescribedby()` which merges the link into the rendered
top-level `links` at render time — symmetric with the by-convention `self`, and
merging into the final links map rather than the construct-only `DocumentLinks` — so
a caller (the Symfony bundle, pointing at the served OpenAPI document) can contribute
it uniformly across every response type without disturbing existing `self`/pagination/
custom links. An author-set `describedby` (via `withLinks()`) wins over
`withDescribedby()`.

We deliberately did **not** add a wither to `DocumentLinks`: it is construct-only by
design, so the render-time merge on `AbstractResponse` (where withers already live) is
the injection point instead.
