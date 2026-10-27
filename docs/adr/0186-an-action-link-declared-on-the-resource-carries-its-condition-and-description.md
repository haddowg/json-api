# An action link declared on the resource carries its condition and description

An `asLink` action rendered its link whenever the action's security passed, so a link that should show only in some states, such as completing a file only while its upload is pending, had to move that state into security, where the handler's specific error became a plain `403`. A resource now declares `ActionLink::for($path)` in `links()`, with an optional `when()` closure over the model and request that the adapter runs after the security check, and an optional `describedAs()` that replaces the generic description in `<Type>ResourceLinks`. The href, method and `describedby` still come from the action, so the declaration only says when the link shows and how the document describes it.

## Considered options

- Put the condition on the action declaration. An adapter's action attribute or route can't hold a closure over the model, and the condition belongs to the resource that renders the link, next to its declared links.
- Describe the condition in the schema. It is arbitrary code, so the projection keeps the link optional and lets the description say when it appears.

## Consequences

Declaring an action link implies the link, so the action needs no `asLink`; when it has one, the declaration's condition and description win. `allLinks()` keeps returning only `DeclaredLink`s and `allActionLinks()` returns the action links, so existing metadata and renderers compile unchanged. Two action links for one path, or a declared link named like an action link, fail when the resource builds its links, naming the resource class. A server without the named action neither renders nor projects the link, so one resource can be served on servers that expose different actions. Each projection sees only its own server, so the adapters fail the build when no server serving the type has the action, which still catches a typo. An action link projects as the same optional property an `asLink` action already did, with its own description, so the generator contract doesn't move.
