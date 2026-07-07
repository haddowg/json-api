# Custom-action success responses declared via a `responds` list

A custom action's success response is now declared as `responds: list<ActionResponse>` on
`ActionMetadataInterface`, reusing the same atomic response carriers as the CRUD/read
operations — `ActionResource` (a `200` resource document), `MetaResult` (a `200` meta-only
document), `NoContent` (`204`), `Accepted` (a `202` async accept) and `SeeOther` (a `303`
completion redirect) — validated by `ActionResponses::validate()`. This replaces the former
single `ActionOutputMode` (`Document`/`Meta`/`None`) + `outputType` pair (and the
integrations' `returns204`/`outputMeta` flags): one mechanism instead of two, and an action
can now advertise the full async lifecycle (`202`/`303`) or any spec-valid mix, exactly as a
CRUD operation does.

This is a pre-1.0 breaking change: the `ActionOutputMode` enum is removed and
`ActionMetadataInterface::outputMode()`/`outputType()` are replaced by `responds()`. The
migration for an integration is mechanical — a `Document` output with type `T` becomes
`[new ActionResource(T)]`, a `Meta` output becomes `[new MetaResult()]`, and a `None` output
(or a `returns204` flag) becomes `[new NoContent()]`.
