# A BelongsToMany's pivot fields are writable field definitions

`BelongsToMany::fields()` previously took a `name => type` map that was
declare-only render/filter/sort metadata. We replace it with real
{@see FieldInterface} definitions — the **same** field DSL used for attributes
(`Integer::make('position')->required()->min(1)`,
`DateTime::make('addedAt')->readOnly()`, `Str::make('note')->maxLength(140)`) —
and `pivotFields(): list<FieldInterface>`. One declaration now drives every pivot
concern: render (the field's value cast), filter / sort (its name + column), and
**write / validate** (its constraints resolved by create/update context, and its
read-only writability). A pivot field is **writable by default**; opt a
server-owned column out with `->readOnly()`, and `writablePivotFields(bool $creating)`
exposes the set settable from the linkage `meta` in that operation context.

The write convention is JSON:API resource-identifier `meta` on each linkage
member (`{ "type":"tracks","id":"7","meta":{"position":3} }`), carried on both the
relationship-endpoint body (`PATCH/POST/DELETE /…/relationships/{rel}`) and the
same relationship inline in a whole-resource write. That `meta` already round-trips
through {@see ResourceIdentifier} (which has always parsed a member's `meta`), so
every linkage parser — the relationship-body and whole-resource paths alike —
already exposes the per-member pivot intent; this change adds no new wire-parsing
surface. Core stays **storage-agnostic**: it carries the field definitions and the
parsed `meta`, and never writes the join row — the Symfony bundle's Doctrine
adapter validates the `meta` against the writable pivot fields' constraints and
persists the association entity (the upsert / reorder diff). This is a pre-1.0
breaking replacement of the map form; the bundle's read consumers move to reading
name / column / constraints off the `FieldInterface`.
