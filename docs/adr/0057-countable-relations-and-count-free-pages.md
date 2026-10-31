# Counting is opt-in, and a total is computed once

A paginator never runs a `COUNT` unless told to: a page is count-free by default (no total, no `last` link, `next` from a one-past-the-window probe), and a total appears only when the author's paginator `withCount()` always counts or a client asks with `?withCount` for a target declared `countable()` (`_self_` names the primary collection). `?withCount` is a negotiated profile (the Countable profile) rather than an always-on parameter, so it is opt-in, self-describing and free of name collisions. Core never computes a count: it reads one through an adapter-supplied `RelationshipCountInterface`, and the relationship total and the pagination total share the `total` key.

## Consequences

`pagination()` is the single source of truth: a resource returns the server default to inherit, its own paginator to pin one, or `null` for an unpaginated collection.
