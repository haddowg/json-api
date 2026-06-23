# Generated description with declarative override on every OpenAPI element

The generated OpenAPI document left several documentable elements without a
`description`: the resource-object component schema (the type's declared
`description()` was on the contract but never consumed), and every CRUD,
related and relationship-endpoint operation (which carried only a terse
`summary`). A consumer browsing the spec saw blank descriptions where a sentence
would help.

The projector now emits a **`description` on every such element** — a generated
default when the author declared none, the author's own value when they did. The
default reads as a short sentence naming the element: a resource object is `An
` + "`<type>`" + ` resource object.`; the CRUD operations are `Returns a
paginated collection of …` / `Returns a single … by its `id`` / `Creates a new
…` / `Updates an existing …` / `Deletes the …`; the related/relationship
operations describe the linkage or related resource(s) in terms of the relation
and parent type. These are deliberately fuller than the one-line `summary`.

The override is **always declarative**, never via the `OpenApiFactory`
decorator: the resource-object description comes from
`TypeMetadataInterface::description()` (already on the contract, now consumed); a
new `TypeMetadataInterface::operationDescription(OperationType): ?string`
overrides one CRUD operation independently of the schema description; a
relationship's `RelationMetadataInterface::description()` (now consumed) applies
to every endpoint of that relationship. On `AbstractResource` the author surface
is `getDescription(): ?string` (resource object) and
`describeOperation(OperationType): ?string` (per operation), both defaulting to
`null` so the generated default stands until overridden; relationships already
carry `describedAs()`/`getDescription()` via `AbstractField`.

For naming consistency the field fluent setter was renamed
`AbstractField::description(string)` → `describedAs(string)` to match the
filters' existing `HasValueConstraints::describedAs()` (the getter
`getDescription()` was already consistent). **Breaking** pre-1.0 (a `feat!`): the
rename, plus the new required `TypeMetadataInterface::operationDescription()`
method that every implementer must supply.
