# "Countable" Profile

## Introduction

This is the specification of a
[profile](https://jsonapi.org/format/1.1/#profiles) for the JSON:API
specification. The URL for this profile is
`https://haddowg.github.io/json-api/profiles/countable/`.

A collection's rendered data tells a client *which* resources are present, but not
*how many* there are in total when the data is not fully materialised — a paginated
primary collection, or a to-many relationship rendered as links-only or paginated,
does not reveal the size of its set. A client that needs the count ("the article,
and how many comments it has"; "how many articles match this filter") must otherwise
fetch the whole collection and count it, or page to the end.

This profile defines a single query-parameter family, `withCount`, that lets a
client ask for the **size of a countable collection alongside the primary resource**
— a named relationship's set, and/or the **primary collection itself** via the
reserved `_self_` token. The server returns each count as a `total` member on the
named relationship object's `meta` (for a relation) or on the top-level `meta` (for
`_self_`). For example:

```http
GET /articles/1?withCount=comments
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/countable/"
```

renders article 1 with `data.relationships.comments.meta.total` set to the number
of comments, in one request and without materialising the comments; and

```http
GET /articles?page[size]=2&withCount=_self_
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/countable/"
```

renders page 1 of the articles collection with the top-level `meta.total` (and, since
the collection is paginated, `meta.page.total` and a `last` link) set to the total
number of matching articles.

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here. This is the same
interpretation the JSON:API specification applies to these key words; see its
[conventions](https://jsonapi.org/format/#conventions).

## Specification

### Concepts

#### Countable collections

Counting is a property a server opts a collection into. Two kinds of collection can
be made countable:

- a **to-many relationship**, named directly (`?withCount=<rel>`). A to-one
  relationship is never countable — its linkage is a single resource identifier, not
  a set.
- the **primary collection**, named by the reserved token **`_self_`**
  (`?withCount=_self_`). This counts the current request's primary data: the
  collection of a `GET /<type>` (or `GET /<type>/<id>/<rel>` related endpoint), gated
  on that resource/relation being countable.

A collection the server has not made countable cannot be counted through this profile
(see [Error Cases](#error-cases)). The two compose: `?withCount=_self_,comments`.

#### The count reflects the rendered set

The `total` a server reports **MUST** equal the size of the same set the collection
would return for the request — that is, it **MUST** honour any filtering the server
applies (default or request-supplied). It is the count of the *filtered* set, not of
raw membership. For a relationship it agrees with the `total` the relationship's own
related-collection endpoint would report for an equivalent request; for `_self_` it
is the total of the filtered primary collection.

#### One count, two slots

When a total is computed — for any reason — it is the **single** cardinality of the
collection. The server **MUST NOT** count twice: the same number is written to the
top-level `meta.total` (the universal cardinality slot) and, additionally, to
`meta.page.total` when the collection is paginated. A paginator that the server has
configured to always count, and a `_self_` requested under this profile against a
countable resource, both produce that one number in both slots.

### Query Parameters

This profile reserves one
[implementation-specific query-parameter family](https://jsonapi.org/format/1.1/#query-parameters-custom):

| Family base | Role |
| --- | --- |
| `withCount` | canonical |

`withCount` is a flat, comma-separated list — the same shape as the
[`include`](https://jsonapi.org/format/1.1/#fetching-includes) parameter — where each
member is either a relationship name of the **primary** resource to be counted, or
the reserved token `_self_` naming the **primary collection** itself:

```
withCount=[_self_,]<relationship-name>[,<relationship-name>…]
```

Each named target that the primary resource exposes as countable is counted; order
is not significant and duplicate names are equivalent to a single mention. A named
relationship that is not countable, is a to-one, or does not exist — or `_self_`
against a resource that is not countable — is an error (see
[Error Cases](#error-cases)).

### Processing

#### Profile negotiation

This profile is advisory and opt-in. A server **MUST** parse and apply the
`withCount` family **only** when the client has negotiated this profile — that is,
when this profile's URI appears in the `profile` parameter of the request `Accept`
media type, per
[content negotiation for profiles](https://jsonapi.org/format/1.1/#profiles). When
the profile is not negotiated, a server **MUST NOT** apply the family; the
parameter carries no special meaning, so a relationship, member, or parameter
named `withCount` never collides with this profile.

#### Counting

For each negotiated, valid relationship name, the server **MUST** add a `total`
member to that relationship object's `meta` whenever the relationship object is
rendered — on a single primary resource, on every member of a primary collection,
and on a related resource's relationship object. For a negotiated, valid `_self_`,
the server **MUST** add the total of the primary collection to the document's
top-level `meta.total` (and `meta.page.total` when the collection is paginated, per
[One count, two slots](#one-count-two-slots)). A target **not** named in `withCount`
**MUST NOT** carry a `total`, even when it is countable: the count is gated by the
request, not emitted by default.

> A collection a server has **not** paginated is fetched whole, so its size is
> already known and counting it is free. A server **MAY** render `meta.total` for an
> unpaginated primary collection (or a materialised to-many relationship)
> unconditionally — without `_self_`/`?withCount` and without an extra query — since
> the count costs nothing.

#### Advertising an applied profile

When a server applies this profile to a response, it **MUST** advertise the
profile as the base specification requires: the profile URI **MUST** be present in
the `profile` parameter of the response `Content-Type` media type and in the
document's top-level `jsonapi.profile` array.

### Document Structure

This profile reserves the `total` member of a **relationship object's** `meta`
object (for a relation count) and of the document's **top-level** `meta` object (for
a `_self_` count). When present, `total` **MUST** be a non-negative integer giving the
size of the (filtered) set. The same number **MAY** additionally appear as
`meta.page.total` when the collection is paginated (the base pagination meta). The
profile reserves no other document members and adds nothing else to the response body.

### Error Cases

Every error defined by this profile is reported as a `400 Bad Request`, following
the base specification's rules for
[processing errors](https://jsonapi.org/format/1.1/#errors-processing). The
response document **MUST** contain an
[error object](https://jsonapi.org/format/1.1/#error-objects) whose `source.parameter`
is `withCount`.

A `withCount` that names a relationship which is **not countable**, names a
**to-one** relationship, or names a relationship that **does not exist** on the
primary resource — or that names `_self_` against a resource that is **not
countable** — is a `400 Bad Request`. A server validates the named set up front,
against the targets the primary resource exposes as countable, before rendering.

## Notes

- `withCount=<rel>` reports the count that
  `GET /<primary>/<id>/<rel>` (the relationship's related resource collection) would
  page under the same filtering, folded onto the primary response as
  `relationships.<rel>.meta.total` — without materialising the related collection.
- `withCount=_self_` reports the total of the primary collection (a `GET /<type>` or a
  related endpoint), folded onto the top-level `meta.total` (and `meta.page.total`
  when paginated) — the same number a count-based paginator's `meta.page.total`
  carries, computed once.
