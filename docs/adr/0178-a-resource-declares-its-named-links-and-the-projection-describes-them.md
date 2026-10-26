# A resource declares its named links and the projection describes them

A link like a file's signed upload URL, with its method, headers and expiry, could be rendered from `getLinks()` but not documented, since every `<Type>ResourceLinks` was `self` plus an open `additionalProperties`. So it ended up in the attributes. A resource now declares such links in `links()` with `DeclaredLink::make()`, the way `fields()` declares attributes: the href comes from a closure, and a `null` href leaves the link out. That is how a link is hidden per viewer or per state. Its `meta` members are fields, which give the projection their type, description and example and make each value render the way that field renders an attribute. The projector lists each link as a named, optional property, and adapters reach the links through the opt-in `DeclaresResourceLinks` type metadata, so existing metadata compiles unchanged. The class is `DeclaredLink` because `ResourceLinks`, `Link` and `LinkObject` already name the containers and value objects a link renders into.

## Considered options

- Render declared links through `getLinks()`'s default. A resource that overrides `getLinks()` would silently lose them, so the transformer merges them in itself, through the opt-in `RendersDeclaredLinksInterface`.
- Pick a link's shape from the response, a string when no meta value is present. The schema would then have to admit both for every link. The declaration decides instead: no meta members and no static members gives a bare string on every response, anything else a link object on every response, even one whose meta members are all hidden.
- Read meta values off the model when no `metaUsing()` is given. Members named `method` or `headers` would read whatever the model happens to have under those names, so a link that declares meta members without a closure for their values fails to build.

## Consequences

When names collide, `getLinks()` wins, then a declared link, then a contributed one such as an action link, then the convention `self`. The author-wins order of ADR 0089 extends to the new source. `self` can't be declared. A declared link sharing a name with an action's link fails the projection, since the document can describe only one of them. At runtime the declared link still wins.

A declared link is part of what a read renders by default, so ADR 0176's rule applies to it as it does to an attribute. With no `href` example, or with a rendered meta member that has no example, the operation gets no example, and a `MissingExample` names `links.<name>` or `links.<name>.meta.<member>`. We rejected leaving just the link out of an otherwise complete example. A link is optional on the wire, so that example would still be true, but a `MissingExample` would then mean two things, and a strict export would miss nothing it could act on.

The new properties are structure a generator reads, so they get a scoped tier, `DECLARED_LINKS = 14`. Only a document whose types declare a link is stamped with it, and every other document keeps the baseline 13 unchanged.
