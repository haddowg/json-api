# A relationship endpoint checks include from the parent before it loads

The spec roots a relationship endpoint's `include` at the parent, as in `/articles/1/relationships/comments?include=comments.author`, and makes an unrecognised path a `400`. Rendering only walked the paths under the requested relationship, so `?include=bogus` or a bad path on another branch answered `200`. So the server now checks `include` on a relationship read, and on a relationship mutation that answers with its linkage, the way it checks every other read: up front, before the parent loads, against the parent's own safeguards and relation tree. The linkage document still carries no `included`.

## Considered options

- Leaving the check to each adapter's handler: both adapters had to reach into an `@internal` guard to do it, and a third would miss it.
- Rejecting `include` on a write-only relation's mutation, which answers `204`: a delete ignores `include` because it renders nothing, and this mutation renders nothing too, so it ignores it the same way.
- Checking a mutation only when its handler turns out to answer `200`: the server can't know that before the handler runs, and checking after the write would refuse a request whose change already landed.

## Consequences

A relationship mutation echoing its linkage now offers `INCLUSION_UNRECOGNIZED` alongside the safeguard codes it already offered. A handler that answers such a mutation `204` of its own accord still refuses a bad path, since the check runs first.
