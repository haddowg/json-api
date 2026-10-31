# A relation's paths are keyed by its URI field name

A relation renamed with `withUriFieldName()` is served, linked and projected under that segment everywhere: the projector keys its paths by it, built links and their `describedby` fragments use it, and a host resolves the routed segment with `relationAtUriFieldName()`. The relationship member, operation ids and `Target` keep the relation's name, because they identify the relation, not its URL, and handlers, hydrators and the atomic `ref` all look relations up by name.

## Considered options

Serving under the name and making only the links follow, which would silently ignore a documented authoring call.
