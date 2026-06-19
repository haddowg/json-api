# OpenAPI path and operation projection

The `OpenApiProjector` now projects **paths** (design §4.4, D10/D12): an
`OperationProjector` collaborator turns each type's allowed CRUD operations into
OAS `PathItem`s — `GET`/`POST` on `/{uriType}` and `GET`/`PATCH`/`DELETE` on
`/{uriType}/{id}` — honouring the per-type operation allow-list
(`TypeMetadataInterface::operations()`), with the `{id}` path parameter shared at
the path-item level. Each operation enumerates its concrete query parameters
(a `filter[<key>]` per declared filter, the `sort` enum of `±key` tokens, the
`include` enum of includable paths, `fields[<type>]`, and the paginator-kind's
`page[…]`), its request body (create/update request component) and the enumerated
standard error responses (all `$ref`'ing the one shared `ErrorDocument`), and
carries the type's tags (§4.7). This is **stage A** (CRUD only); the
relationship-endpoint and custom-action paths (stage B) reuse the same public
parameter helpers, which are keyed off the contract so they apply unchanged to a
relation's related-collection endpoint.

The path projection needed two supporting changes:

- **`SchemaProjector::projectConstraints()`** — a public constraint-list → `Schema`
  entry point (no owning field) so a `filter[<key>]` value schema can be projected
  from the filter's declared value constraints, reusing the existing
  constraint→keyword machinery in the read context.
- **`ComponentNaming`** — the PascalCase type/member → component-name derivation is
  extracted into a shared helper so the path-side `$ref`s name the **exact**
  components the component projection (ADR 0073) emitted; `OpenApiProjector` delegates
  to it.

It also **fixes the single-resource document envelope**: `<Type>Document.data` is now
a **non-nullable** resource reference (it describes a primary `GET /{type}/{id}`
fetch, where JSON:API mandates a present resource object — a `null` primary `data` is
only valid for an empty to-one *related* endpoint, described separately by the
relationship-object schema).

## Per-operation security is a boolean intent on the contract

D8 says operations carrying a security expression get the *configured* per-operation
security requirement, and we never infer scheme semantics from the authz expression.
The contract carried security only at the document level
(`ServerMetadataInterface::defaultSecurity()`) and per-action
(`ActionMetadataInterface::isSecured()`) — there was **no signal for CRUD
operations**. So `TypeMetadataInterface` gains `securedOperations(): list<OperationType>`
(the subset of `operations()` carrying a security expression). The projector emits
the document-level `defaultSecurity()` as that operation's `security` when it is in
the set, and emits no per-operation `security` otherwise (the operation inherits the
document default). The contract carries only the **intent** (which operations are
secured); the requirement VOs always come from the configured default — mirroring the
action `isSecured()` design exactly. This is a small, additive pre-1.0 contract method
(the bundle, Slice 4, populates it from the resolved authz config). The relationship
and related endpoints (stage B) reuse the same secured-operation intent.

## Stage B — relationship & related endpoints, custom actions, envelope refinements

The `OperationProjector` now also projects, per type:

- **Related & relationship endpoints**, gated by the per-relation exposure facts
  (`RelationMetadataInterface::exposesRelatedEndpoint()` /
  `exposesRelationshipEndpoint()`) and mutation flags (`allowsReplace`/`allowsAdd`/
  `allowsRemove`). The `{rel}` URI segment is **literal** in the projected document
  (one `PathItem` per concrete relation name, e.g. `/articles/{id}/author`), not a
  parametric segment. A to-one related endpoint responds with a **nullable** related
  document; a to-many related endpoint reuses the related type's collection envelope
  and carries the relation's own filter/sort/page parameters (the same public helpers
  stage A built). On `…/relationships/{rel}`: `GET` (linkage read — always when the
  endpoint is exposed), `PATCH` (replace, gated `allowsReplace`), and — to-many only —
  `POST` (add, `allowsAdd`) / `DELETE` (remove, `allowsRemove`); a to-one therefore
  never emits `POST`/`DELETE`. The linkage read mirrors `FetchOne` security and a
  mutation mirrors `Update` security, reusing `securedOperations()` (no new contract
  signal — the bundle/decorator can refine per-endpoint later).

- **Custom-action paths** from `ActionMetadataInterface`: one path per action under the
  `-actions` segment (`/{uriType}/{id}/-actions/{path}` for `ActionScope::Resource`,
  `/{uriType}/-actions/{path}` for `Collection`), carrying the declared method(s). The
  input mode drives the request body — `None` → no body; `Document` → the input type's
  create-request schema under `application/vnd.api+json`; `Raw` → a permissive
  `application/octet-stream` binary body (the action owns negotiation). The output type
  → its `<Type>Document`, or a `204` when absent. Per-action `security` when
  `isSecured()`, and the action's own tags.

**Envelope refinements.** Two new per-relation document envelopes are emitted **only
when the corresponding endpoint is exposed** (so component emission tracks path
emission exactly, leaving no dangling `$ref`): a **relationship document**
(`<Base><Rel>RelationshipDocument`, `data` = the bare linkage — an array for to-many,
a nullable identifier for to-one) for the relationship endpoint, and a **related-to-one
document** (`<Base><Rel>RelatedDocument`, `data` = the related resource ref widened
with `null`, since an empty to-one renders `data: null`) for an exposed to-one related
endpoint. The primary single document (`<Type>Document`) keeps its stage-A
non-nullable `data`. Any unregistered related type that an exposed related endpoint
targets gets a synthesized permissive `<RelatedType>Resource` + `<RelatedType>Collection`
(alongside its always-emitted identifier), so the document stays self-contained.

**The parameter/header `unevaluatedProperties` relaxation is benign** (a documented
opis 2.6 limitation, fixture README): the projector emits a parameter/header only via
the typed `Parameter`/`Header` VOs, whose serialization is a **closed shape** that
cannot carry an unknown member — pinned by `ParameterClosedShapeTest`, independently of
the relaxed meta-schema. So we did not fight opis further.

## Review fixes (stage-B correctness)

A post-stage-B review surfaced four projection defects, all fixed here:

- **Secured-op security-intent inversion.** An operation/action in the secured set
  emitted the document default verbatim — but an *empty* document default
  (`ServerMetadataInterface::defaultSecurity()` is `[]`, explicitly valid) produced
  `security: []`, which in OAS 3.1 declares auth *optional* for that op — the inverse
  of the secured intent. `securityFor()` (and the action branch) now treat an empty
  default as "nothing to attach": a secured op under an empty default emits **no**
  per-operation `security` (inheriting the equally-empty document default) rather than
  the auth-optional empty list.

- **Polymorphic to-many related collection dropped non-first members.** A polymorphic
  to-many related endpoint (`GET …/{rel}` over `images|videos`) reused the *first*
  member type's `<Type>Collection` envelope, so a response containing another member
  would not validate. It now `$ref`s a **per-relation** `<Base><Rel>RelatedCollection`
  document whose `data.items` is the `anyOf` of every member resource (mirroring the
  to-one polymorphic related document) — emitted only when the related endpoint is
  exposed, so component emission still tracks path emission. A *monomorphic* to-many
  still reuses the single related type's `<RelatedType>Collection`.

- **Related-endpoint `?include` was the parent's, not the related type's.** A related
  endpoint returns the *related* resource(s) as primary data, so its `?include` resolves
  relative to the related type — but both arms passed the parent's `includablePaths()`.
  `RelationMetadataInterface` gains `relatedIncludablePaths(): list<string>` (the related
  type's includable paths for that relation, respecting its safeguards; empty for a
  polymorphic relation with no shared include vocabulary); the related operation sources
  its `include` parameter from it. A small additive pre-1.0 contract method (the bundle
  populates it in Slice 4), in the same spirit as `securedOperations()`.

- **`fields[<type>]` was emitted only for the operation's own type (D10 unfulfilled).**
  D10/§4.4 require one `fields[<type>]` per type *reachable in the document* via
  includes; stage B emitted only the owning type's. `fieldsParameters()` now widens to
  every **registered, field-bearing** type reachable along the operation's includable
  paths — resolved by walking the server's relation graph segment-by-segment to each
  path's terminal type(s) (a polymorphic segment branches; an unresolvable segment is
  pruned, never emitting a wrong parameter). The related endpoints, which previously
  emitted no `fields[]` at all, now emit them scoped to the related type(s) plus their
  reachable includes. Only types whose field inventory the document actually carries
  yield a parameter (you cannot soundly advertise `fields[<t>]` for a type you don't
  describe).
