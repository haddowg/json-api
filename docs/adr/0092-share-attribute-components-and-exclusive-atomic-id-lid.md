# Share one attributes component per shape, and make atomic `id`/`lid` mutually exclusive

The generated document emitted a `<Type>Attributes` component that nothing
referenced — the resource object, the create/update request bodies and the
`<Type>AtomicWrite` object each **inlined** their own attribute block — so the
same property set was written out three or four times per type and the standalone
component was dead weight.

There are only **two** distinct attribute shapes per type: the **read** shape
(`projectAttributes(creating: false)`, no `required`) and the **write** shape
(`projectAttributes(creating: true)`, carrying the create-context `required`).
Each is now emitted **once** and `$ref`'d where it is reused:

- `<Type>Attributes` (read) ← the resource object **and** the update request body.
- `<Type>WriteAttributes` (write) ← the create request body **and** the
  `<Type>AtomicWrite` object.

`projectResourceObject()` gained an optional `$attributesRef`; the create/update
request builders and the atomic write builder reference the components directly.
Componentising only the two shapes that are actually reused (rather than one
component per endpoint) keeps every emitted component referenced — no orphan, no
single-use indirection. A side benefit: `<Type>AtomicWrite` previously projected
its attributes with a `null` enum collector (inlining a backed enum where the
create body `$ref`'d it); sharing `<Type>WriteAttributes` makes the two
consistent.

Separately, an atomic write / resource-identifier object could carry **both**
`id` and `lid`. A resource is identified by a client/path `id`, a local `lid`, or
neither (a server-assigned `add`) — never both — so `<Type>AtomicWrite` and the
generic resource-identifier shape now carry `not: { required: [id, lid] }`
(rejecting both-present while leaving either-or-neither valid).
