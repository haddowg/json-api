# "Relationship Queries" Profile

## Introduction

This is the specification of a [profile](https://jsonapi.org/format/1.1/#profiles)
for the JSON:API specification. The URL for this profile is
`https://haddowg.github.io/json-api/profiles/relationship-queries/`.

A JSON:API server can expose a related resource collection at its own endpoint
(`GET /articles/1/comments`) and let the client `sort`, `filter`, and `page` it
there. But the same collection also appears as a **relationship** of a *primary*
resource — as full linkage under `?include`, as links-only linkage, or in a
compound document — and the base specification gives the client no way to order or
narrow that linkage from the primary request. A client that wants "the article,
with only its *approved* comments, newest first" must therefore issue a second,
separate request against the relationship endpoint and correlate the two responses
itself.

This profile closes that gap. It defines a single query-parameter family,
`relatedQuery` (with a shorthand alias `rQ`), that lets a client **sort and filter
a relationship's linkage from the primary request**, addressing the relationship by
its include path. The relationship's sort and filter vocabulary is exactly the one
its own related-collection endpoint already exposes — this profile only changes
*where* the client may apply it, not *what* may be applied.

For example, under this profile the request

```
GET /articles/1?include=comments&relatedQuery[comments][filter][approved]=true&relatedQuery[comments][sort]=-createdAt
```

returns article `1` with its `comments` linkage (and the corresponding `included`
resources) restricted to approved comments, newest first — in one round trip.

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here. This is the same
interpretation the JSON:API specification applies to these key words; see its
[conventions](https://jsonapi.org/format/#conventions).

## The `relatedQuery` family

The profile reserves two query-parameter family bases with identical semantics:

| Base | Role |
| --- | --- |
| `relatedQuery` | canonical |
| `rQ` | shorthand alias |

Both bases are legal JSON:API implementation-specific query parameters: the base
specification reserves all-lowercase family names for current and future standard
use and requires an implementation-specific family to contain **at least one
non `a-z` character** — each base here carries an uppercase `Q`, so both satisfy
that rule.

A member of the family addresses a relationship by **path** and names an
**operation** on it:

```
relatedQuery[<relationship-path>][sort]   = <sort-fields>
relatedQuery[<relationship-path>][filter][<key>] = <value>
```

- **`<relationship-path>`** is the relationship's **include path** as used by the
  `include` query parameter — the relationship *name*, or a dotted path that
  reaches a relationship of an included resource (e.g.
  `relatedQuery[author.articles][sort]=-title`). The path is a single bracket
  segment; the dot is part of the key, consistent with the `include` grammar. The
  path MUST resolve, from the primary resource, to a relationship the server can
  address; if it does not, the server MUST respond according to the rules for the
  [invalid query parameter error](#errors).
- **`[sort]`** takes the same comma-separated, optionally `-`-prefixed sort-field
  list as the relationship's related-collection endpoint. It MAY only be applied to
  a **to-many** relationship (see [To-one relationships](#to-one-relationships)).
- **`[filter]`** is itself a family: `[filter][<key>]=<value>` carries the same
  filter keys the relationship's related-collection endpoint accepts.

The `rQ` alias is interchangeable with `relatedQuery` member for member. A server
MUST treat `rQ[comments][sort]=-createdAt` exactly as
`relatedQuery[comments][sort]=-createdAt`.

### `page` is not part of this profile

The profile deliberately reserves **no** `page` operation. An addressed
relationship always renders **page 1** of its (ordered, filtered) linkage. A client
navigates the remaining pages through the **relationship object's own pagination
links** (`first`/`prev`/`next`, and `last` when the relationship is countable),
which the server emits in the response in the base specification's plain query form
against the relationship's own endpoint — never in this profile's `relatedQuery`
form. The `relatedQuery` form addresses a relationship only *from a primary
request*; the pagination links address the relationship *directly*, so they use the
form that endpoint understands.

This keeps a compound document bounded: an `?include` that fans out across a page
of parents windows each parent's relationship to one page rather than materialising
every related resource.

### To-one relationships

`[sort]` and `page` have no meaning for a to-one relationship — its linkage is a
single resource identifier, not a list. On a to-one path:

- `[filter]` is permitted. A `relatedQuery[<toOne>][filter]` whose constraints
  **exclude** the related resource renders the linkage as `data: null` and omits
  the resource from `included`. (The relationship resolves to *the related resource
  if it matches the filter, otherwise nothing*.)
- `[sort]` MUST be rejected according to the rules for the
  [invalid query parameter error](#errors).

## Negotiation

This profile is **advisory** and **opt-in**, like every JSON:API profile. A server
MUST parse and apply the `relatedQuery` / `rQ` families **only** when the client has
negotiated this profile's URI — that is, when the URI appears in the `profile`
parameter of the request's `Accept` media type:

```
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"
```

When the profile is not negotiated, the server MUST ignore the families entirely
(neither applying nor rejecting them). This is what makes the custom family safe:
a relationship, filter key, or member literally named `relatedQuery` can never
collide with the profile, because outside negotiation the family carries no special
meaning.

A server that applies the profile to a response MUST advertise it as the base
specification requires for an applied profile: the URI is echoed in the response's
`Content-Type` `profile` parameter and listed in the document's top-level
`links.profile`, and the response sets `Vary: Accept`.

## Conflict resolution

A request MAY carry both family bases. When the canonical `relatedQuery` and the
shorthand `rQ` target the **same** `[path][operation]`, the canonical
`relatedQuery` **wins** and the `rQ` member is discarded for that target — this is
not an error. Members targeting different paths or operations are merged.

## Errors

This profile distinguishes a **structural** error in the family itself from a
**semantic** error in the addressed sort/filter vocabulary.

- A **structurally malformed** member under a negotiated profile — a non-array
  family value, a non-string `[sort]`, or a non-array `[filter]` — is a `400 Bad
  Request`. The error's `source.parameter` MUST identify the offending parameter.
- A **semantically invalid** member — a `[sort]` field or `[filter]` key the
  addressed relationship does not expose, a path that does not resolve or does not
  address a relationship, or a `[sort]`/`page` on a to-one path — MUST be rejected
  exactly as the relationship's own related-collection endpoint would reject the
  equivalent plain `sort`/`filter` parameter, producing the same `400`. The profile
  reuses the relationship's existing vocabulary and validation rather than defining
  its own.

The "invalid query parameter error" referenced above is the base specification's
`400 Bad Request` for an unrecognised or malformed query parameter.

## Notes

- The profile reserves the query-parameter names `relatedQuery` and `rQ`. It
  reserves no document members and adds nothing to the response body beyond the
  relationship pagination links already described, which are part of the base
  specification's relationship object.
- `relatedQuery[<path>]` is equivalent to applying the bracketed `sort`/`filter` to
  the request `GET /<primary>/<id>/<path>` (the relationship's related-collection
  endpoint) and folding page 1 of that result into the primary response as linkage.
  A server MAY implement the profile as exactly that translation.
