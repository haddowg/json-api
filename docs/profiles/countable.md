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

renders page 1 of the articles collection with the top-level `meta.total` set to the
total number of matching articles (and, since the collection is paginated, the server
**MAY** also surface that total as `meta.page.total` in the base pagination meta).

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

Counting is a property a server makes a collection expose. Two kinds of collection can
be made countable:

- a **to-many relationship**, named directly (`?withCount=<rel>`). A to-one
  relationship is never countable — its linkage is a single resource identifier, not
  a set.
- the **primary collection**, named by the reserved token **`_self_`**
  (`?withCount=_self_`). This counts the current request's primary data: the
  collection of a `GET /<type>` (or `GET /<type>/<id>/<rel>` related endpoint), gated
  on that resource/relation being countable.

Whether any given collection is countable is entirely at the server's discretion; this
profile does **NOT** require counting to be opt-in. A server **MAY** make every to-many
relationship and every primary collection countable, or none, or any subset. The
profile's only obligation is that a `withCount` naming a target the server has not made
countable is rejected (see [Error Cases](#error-cases)). The two kinds compose:
`?withCount=_self_,comments`.

#### The count reflects the rendered set

The `total` a server reports **MUST** equal the size of the same set the collection
would return for the request — that is, it **MUST** honour any filtering the server
applies (default or request-supplied). It is the count of the *filtered* set, not of
raw membership. For a relationship it agrees with the `total` the relationship's own
related-collection endpoint would report for an equivalent request; for `_self_` it
is the total of the filtered primary collection.

#### One count, two slots

For a `_self_` count the server **MUST** expose the total as the top-level
`meta.total` (the universal cardinality slot). When the primary collection is
paginated, a server **MAY** also surface the same total in the base pagination meta
(`meta.page.total`); doing so is governed by the base specification's pagination
rules, not by this profile. When both `meta.total` and `meta.page.total` are present
for the same collection, they **MUST** be equal.

### Query Parameters

This profile reserves one
[implementation-specific query-parameter family](https://jsonapi.org/format/1.1/#query-parameters-custom):

| Family base | Role |
| --- | --- |
| `withCount` | canonical |

The family base `withCount` contains a non-`a-z` character (an uppercase letter),
satisfying the base specification's naming rule for an implementation-specific
query-parameter family. An implementation of this profile **MUST** use this exact
family name.

`withCount` is a flat, comma-separated list — the same shape as the
[`include`](https://jsonapi.org/format/1.1/#fetching-includes) parameter — where each
member is either a relationship name of the **primary** resource to be counted, or
the reserved token `_self_` naming the **primary collection** itself:

```
withCount=[_self_,]<relationship-name>[,<relationship-name>…]
```

This profile reserves the literal token `_self_` to name the primary collection within
`withCount`. A server implementing this profile **MUST** interpret a `_self_` member as
the primary-collection count and **MUST NOT** treat it as a relationship name;
consequently `_self_` is not a usable relationship name under this profile. A client
**MUST** spell the primary-collection target exactly `_self_`.

Each named target that the primary resource exposes as countable is counted; order
is not significant and duplicate names are equivalent to a single mention. A named
relationship that is not countable, is a to-one, or does not exist — or `_self_`
against a resource that is not countable — is an error (see
[Error Cases](#error-cases)).

### Processing

#### Profile negotiation

This profile is advisory and opt-in. It is negotiated **only** by listing its URI in
the `profile` media-type parameter of the request `Accept` header, per
[content negotiation for profiles](https://jsonapi.org/format/1.1/#profiles); this
profile defines no other negotiation channel (a `profile` query parameter or `Link`
does not by itself enable `withCount`). A server **MUST** parse and apply the
`withCount` family **only** when the client has negotiated this profile in that way.

When the profile is **not** negotiated, the server **MUST NOT** ascribe this profile's
meaning to `withCount`; per the base specification a server **MAY** either ignore the
parameter **OR** reject it as an unrecognized query parameter (`400 Bad Request`). A
client therefore **MUST NOT** rely on `withCount` having any effect unless it has
negotiated the profile.

#### Counting

For each negotiated, valid relationship name, the server **MUST** add a `total`
member to that relationship object's `meta` whenever the relationship object is
rendered — on a single primary resource, on every member of a primary collection,
and on a related resource's relationship object. For a negotiated, valid `_self_`,
the server **MUST** add the total of the primary collection to the document's
top-level `meta.total` (and **MAY** additionally surface it as `meta.page.total` when
the collection is paginated, per [One count, two slots](#one-count-two-slots)). A
target **not** named in `withCount` **MUST NOT** carry a `total`, even when it is
countable: the count is gated by the request, not emitted by default.

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

A server **MUST** validate the full requested `withCount` set before rendering, and
**MUST NOT** render a partial document and then fail. If any requested target is
invalid, the server **MUST** reject the whole request before producing the document.

Each of the following is a client error: a target that **does not exist** on the
primary resource, a target that is a **to-one** relationship, or a target the server
has **not declared countable** (including `_self_` against a resource that is not
countable). For any such case the server **MUST** respond `400 Bad Request`, following
the base specification's rules for
[processing errors](https://jsonapi.org/format/1.1/#errors-processing), with an
[error object](https://jsonapi.org/format/1.1/#error-objects) whose `source.parameter`
is `withCount`.

## Notes

- `withCount=<rel>` reports the count that
  `GET /<primary>/<id>/<rel>` (the relationship's related resource collection) would
  page under the same filtering, folded onto the primary response as
  `relationships.<rel>.meta.total` — without materialising the related collection.
- `withCount=_self_` reports the total of the primary collection (a `GET /<type>` or a
  related endpoint), folded onto the top-level `meta.total` (and the same total
  **MAY** also appear as `meta.page.total` when paginated) — for a server that already
  computes the total for its own pagination, this is the same total it would otherwise
  expose through the base pagination meta.
