# "Relationship Queries" Profile

## Introduction

This is the specification of a
[profile](https://jsonapi.org/format/1.1/#profiles) for the JSON:API
specification. The URL for this profile is
`https://haddowg.github.io/json-api/profiles/relationship-queries/`.

A JSON:API server can expose a related resource collection at its own endpoint
(for example `GET /articles/1/comments`) and let the client
[sort](https://jsonapi.org/format/1.1/#fetching-sorting),
[filter](https://jsonapi.org/format/1.1/#fetching-filtering), and
[paginate](https://jsonapi.org/format/1.1/#fetching-pagination) it there. The
same collection also appears as a **relationship** of a *primary* resource — as
linkage in a relationship object, including when that relationship is requested
with [`include`](https://jsonapi.org/format/1.1/#fetching-includes) — but the base
specification provides no way to order or narrow that linkage from the request for
the primary resource. A client that wants "article 1, with only its *approved*
comments, newest first" must issue a second request against the relationship's
endpoint and correlate the two responses itself.

This profile defines a single query-parameter family, `relatedQuery` (with a
shorthand alias `rQ`), that lets a client **sort and filter a relationship's
linkage from the request for the primary resource**, addressing the relationship
by its include path. For example:

```http
GET /articles/1?include=comments&relatedQuery[comments][filter][approved]=true&relatedQuery[comments][sort]=-createdAt
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"
```

returns article 1 with its `comments` linkage — and the corresponding `included`
resources — restricted to approved comments, newest first, in one request.

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here. This is the same
interpretation the JSON:API specification applies to these key words; see its
[conventions](https://jsonapi.org/format/#conventions).

## Specification

### Concepts

#### Addressing a relationship

A relationship is addressed by its **include path**: the path the client would
use with the [`include`](https://jsonapi.org/format/1.1/#fetching-includes) query
parameter to request that relationship in a compound document. A path is a single
relationship name (for example `comments`), or a dotted path that reaches a
relationship of an included resource (for example `author.articles`).

A server **MUST** support addressing a relationship of the primary resource (a
single-segment path). A server **MAY** support addressing a relationship of an
included resource (a multi-segment path). A path the server does not support, or
that does not resolve to a relationship of the addressed resource, is an
[Unaddressable Path Error](#unaddressable-path-error).

#### Borrowed sort and filter vocabulary

This profile does not define a sort or filter vocabulary. The value of a `[sort]`
member is interpreted exactly as a [`sort`](https://jsonapi.org/format/1.1/#fetching-sorting)
query parameter on the addressed relationship's related resource collection, and a
`[filter]` member exactly as that collection's
[`filter`](https://jsonapi.org/format/1.1/#fetching-filtering) family. A field or
key the relationship's own endpoint would not accept is equally unacceptable here
(see [Error Cases](#error-cases)). The profile changes only *where* the client may
apply that vocabulary — from the primary request — not *what* may be applied.

### Query Parameters

This profile reserves two
[implementation-specific query-parameter families](https://jsonapi.org/format/1.1/#query-parameters-custom):

| Family base | Role |
| --- | --- |
| `relatedQuery` | canonical |
| `rQ` | shorthand alias |

Members use the bracketed
[query-parameter family](https://jsonapi.org/format/1.1/#query-parameters-families)
grammar, keyed by relationship path and operation:

```
relatedQuery[<path>][sort]            = <sort-fields>
relatedQuery[<path>][filter][<key>]   = <value>
```

#### `relatedQuery[<path>][sort]`

The value, if present, **MUST** be a string in the form of a
[`sort`](https://jsonapi.org/format/1.1/#fetching-sorting) query parameter — a
comma-separated list of sort fields, each optionally prefixed with `-` for
descending order. A `[sort]` member **MUST** address a to-many relationship; on a
to-one path it is a [To-One Sort or Page Error](#to-one-sort-or-page-error). A
`[sort]` value that is not a string is a
[Malformed Parameter Error](#malformed-parameter-error).

#### `relatedQuery[<path>][filter]`

The `[filter]` member is itself a family: `[filter][<key>]=<value>` carries the
filter keys and values the addressed relationship's related resource collection
accepts under [`filter`](https://jsonapi.org/format/1.1/#fetching-filtering). A
`[filter]` member whose value is not itself a family (bracketed) member is a
[Malformed Parameter Error](#malformed-parameter-error).

#### The `rQ` shorthand and conflicts

`rQ` is an alias for `relatedQuery` with identical semantics. A server **MUST**
treat `rQ[<path>][<op>]` exactly as `relatedQuery[<path>][<op>]`. When both
families carry a member for the **same** `[<path>][<op>]`, the canonical
`relatedQuery` member **MUST** take precedence and the `rQ` member for that target
**MUST** be ignored; this **MUST NOT** be treated as an error. Members targeting
different paths or operations from the two families are combined.

#### Pagination is out of scope

This profile reserves no `page` operation. A relationship addressed by this
profile **MUST** be rendered as the **first page** of its ordered, filtered
linkage. A `[page]` member is undefined: on a to-many path a server **MUST**
ignore it (the first page is rendered regardless), and on a to-one path it is a
[To-One Sort or Page Error](#to-one-sort-or-page-error). A client navigates the
remaining pages through the relationship object's own pagination links (see
[Document Structure](#document-structure)), not through this family.

### Processing

#### Profile negotiation

This profile is advisory and opt-in. A server **MUST** parse and apply the
`relatedQuery` and `rQ` families **only** when the client has negotiated this
profile — that is, when this profile's URI appears in the `profile` parameter of
the request `Accept` media type, per
[content negotiation for profiles](https://jsonapi.org/format/1.1/#profiles). When
the profile is not negotiated, a server **MUST NOT** apply the families and
**MUST NOT** reject a request on their account; the parameters are ignored.

#### To-many relationships

For a negotiated to-many relationship, the server **MUST** apply the addressed
`[sort]` and `[filter]` to that relationship's linkage and render the first page
of the result. The members the server includes in `included` for that
relationship (when it is requested with `include`) **MUST** correspond to the
linkage the server renders.

#### To-one relationships

A to-one relationship's linkage is a single resource identifier, not a list.

- A `[sort]` or `[page]` member on a to-one path is a
  [To-One Sort or Page Error](#to-one-sort-or-page-error).
- A `[filter]` member **MAY** be applied. When the filter excludes the related
  resource, the server **MUST** render the relationship's linkage as `data: null`
  and **MUST** omit that resource from `included`.

#### Advertising an applied profile

When a server applies this profile to a response, it **MUST** advertise the
profile as the base specification requires: the profile URI **MUST** be present in
the `profile` parameter of the response `Content-Type` media type and in the
document's top-level `jsonapi.profile`.

### Document Structure

This profile reserves no document members and adds nothing to the response body.

A relationship addressed by this profile is rendered using the relationship object
the base specification already defines. For a to-many relationship rendered with
more than one page of linkage, the server **SHOULD** include
[pagination links](https://jsonapi.org/format/1.1/#fetching-pagination) (`first`,
`prev`, `next`, and `last` where the total is known) in that relationship object's
`links`, so the remaining pages are reachable. These links **MUST** be expressed
in the base specification's plain query form against the relationship's own
endpoint (for example `?sort=-createdAt&page[number]=2`), **NOT** in this
profile's `relatedQuery` form, which addresses a relationship only from a request
for a primary resource.

### Error Cases

Every error defined by this profile is reported as a `400 Bad Request`, following
the base specification's rules for
[processing errors](https://jsonapi.org/format/1.1/#errors-processing). Each
response document **MUST** contain an
[error object](https://jsonapi.org/format/1.1/#error-objects) whose `source`
member identifies the offending query parameter in its `source.parameter`.

#### Malformed Parameter Error

A member that is structurally malformed under a negotiated profile — a
`relatedQuery` / `rQ` value that is not a family (bracketed) member, a `[sort]`
value that is not a string, or a `[filter]` value that is not itself a family —
is a `400 Bad Request`.

#### Unsupported Sort or Filter Error

A `[sort]` field or `[filter]` key the addressed relationship's related resource
collection does not accept is a `400 Bad Request` — the same response the server
would produce for the equivalent plain
[`sort`](https://jsonapi.org/format/1.1/#fetching-sorting) /
[`filter`](https://jsonapi.org/format/1.1/#fetching-filtering) parameter on that
relationship's own endpoint.

#### Unaddressable Path Error

A `<path>` that does not resolve to a relationship of the addressed resource, or
that the server does not support addressing (see
[Addressing a relationship](#addressing-a-relationship)), is a `400 Bad Request`.

#### To-One Sort or Page Error

A `[sort]` or `[page]` member on a path that resolves to a to-one relationship is
a `400 Bad Request`; a single resource identifier has nothing to order or page.

## Notes

- `relatedQuery[<path>]` is equivalent to applying the bracketed `sort` and
  `filter` to the request `GET /<primary>/<id>/<path>` — the addressed
  relationship's related resource collection — and folding the first page of that
  result into the primary response as that relationship's linkage. A server **MAY**
  implement the profile as exactly that translation.
- Because the families are ignored unless the profile is negotiated, a
  relationship, filter key, or member literally named `relatedQuery` or `rQ` never
  collides with this profile.
