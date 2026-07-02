# Sharpen the OpenAPI projection's fieldset parameters and object links/meta

The OpenAPI projection left two known-facts unstated. `fields[<type>]` projected as
a bare `{type: string}` even though the selectable member vocabulary is fully known
and the runtime `400`s an unknown member (`FieldsetMemberUnrecognized`, strict query
params default on); and the resource-object and relationship-object `links`/`meta`
projected as bare `{type: object}` while the shared `Links`/`Meta` components (already
`$ref`'d at the document level) describe exactly what those members carry. We now
project `fields[<type>]` the same way as `sort`/`include` — a `form`/`explode: false`
array whose `items` enumerate the type's read-representation members (read-visible
attributes + relation names, `id` excluded) — and `$ref` the shared `Links`/`Meta`
components for the resource and relationship objects (`Links`, not `PaginationLinks`:
neither object carries pagination, only the conventional `self`/`related` pair). This
gives a client and a code generator completion that matches what the server accepts,
with no runtime behaviour change. The standalone `/schemas.json` projection has no
components to share, so it keeps the inline permissive objects.
