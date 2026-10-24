# A relation's paths are keyed by its URI field name

`withUriFieldName()` renames a relation's related and relationship endpoints, and the built links already used that segment, but the projector keyed the paths by the relation's name and the adapters routed by it too, so the links named endpoints that answered `404` and carried no `describedby` (ADR 0158). The segment now wins everywhere: the projector keys the paths by the URI field name when the metadata implements the opt-in `DeclaresRelationUriFieldName`, `OperationReference::forOperation()` maps a target's relation name to it, and a host resolves the routed segment with `AbstractResource::relationAtUriFieldName()` and builds its `Target` with the relation's name. The relationship member, the operation ids and the `Target` keep the name, because those identify the relation rather than its URL.

## Considered options

- Keep serving under the name and make the links follow. That silently ignores a documented authoring call, and the name may not be something the API wants in its URLs.
- Put the segment in `Target`. Handlers, hydrators and the atomic `ref` all look relations up by name, so the translation happens once where the URL is parsed.
