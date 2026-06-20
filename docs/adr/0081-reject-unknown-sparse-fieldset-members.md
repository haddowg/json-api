# Reject unknown `fields[type]` sparse-fieldset members under the existing strict gate

`?fields[articles]=title,bogus` previously dropped the unknown `bogus` member
silently, the same wrong-but-`200` failure mode `?include=bogus` had before
{@see \haddowg\JsonApi\Exception\InclusionUnrecognized}. We now **reject an
unknown sparse-fieldset member with a `400`**
({@see \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized}, code
`FIELDSET_MEMBER_UNRECOGNIZED`, `source.parameter = fields`) — gated on the
**existing** `strictQueryParameters` flag (no new config key). This **broadens
that flag's meaning** from "reject unknown query-parameter *families*" to "reject
unknown query *input* including sparse-fieldset members"; with the flag relaxed,
members are tolerated exactly as before.

The check lives in `Server::validateStrictQueryParametersOf()`, after the family
validation, so the one gate covers **both** the programmatic `dispatch()` path and
the PSR-15 adapter hook. It runs from the **resource registry pre-render**, not
from the transformed result, so a `fields[type]` for a *secondary/included* type
— and any requested type even when the primary collection is empty — is still
validated.

The known-member set is the resource's **full declared namespace**,
**request-independent**: every name from the field inventory (attributes AND
relationships, INCLUDING hidden / write-only / conditionally-hidden / non-sparse
fields and `id`), exposed by a narrow new
{@see \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface} (a list of names),
which {@see \haddowg\JsonApi\Resource\AbstractResource} implements. A member is
"unknown" only when it names no declared field at all, so a **hidden field name
and a bogus name behave identically (both tolerated when hidden) — no information
leak**; `notSparseField()` fields and `id` are real declared fields and so are
tolerated.

Two cases are deliberately **tolerated** (out of scope for L#37): a `fields[type]`
naming an **unregistered / unresolvable type** (only members of KNOWN types are
checked), and a type whose serializer does **not** declare its field names — the
`Server` checks `instanceof DeclaresFieldNamesInterface` and skips otherwise,
exactly as the transformer checks `instanceof IncludeControlsInterface`, so a
standalone bare serializer with no field inventory is never validated.

**Breaking**: default-on, so it changes behaviour for a client that was relying on
the silent-drop. Pre-1.0, this is a minor bump (`feat!`).
