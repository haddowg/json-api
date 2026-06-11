# Paginated related-collection responses

`RelatedResponse::fromPage()` paginates a related to-many collection response
(`GET /{type}/{id}/{rel}`) the same way `DataResponse::fromPage()` paginates a
primary collection: the page's `links.{first,prev,self,next,last}` and `meta.page`
are merged post-transform, **scoped to the related-collection URL the client hit**
(e.g. `/articles/1/comments`, preserving its query string) rather than a
reconstructed path, so a paginated related collection is wire-identical to a
paginated primary collection. The shared application lives in
`AppliesPaginationTrait`, used by both responses so the behaviour cannot drift;
`fromCollection()` stays for the unpaginated case (no `page[…]` window applies).
