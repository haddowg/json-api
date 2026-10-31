# A resource declares its named links, and the projection describes them

A link worth documenting (a signed upload URL with method and expiry) is declared in a resource's `links()` beside its fields, with an href closure (a `null` href omits it) and `meta` members that are fields, so the projection lists it as a named optional property. An action's link is declared there too, adding a state condition and description while the href still comes from the action. Declared links are merged by the transformer, not through `getLinks()`'s default, so a resource overriding `getLinks()` cannot lose them.

## Consequences

On a name collision the precedence is `getLinks()`, then a declared link, then a contributed or action link, then the convention `self`. A link's shape (string or link object) is fixed by its declaration, not by the response, so the schema admits one shape.
