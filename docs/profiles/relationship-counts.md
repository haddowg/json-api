# "Relationship Counts" Profile

## Introduction

This is the specification of a
[profile](https://jsonapi.org/format/1.1/#profiles) for the JSON:API
specification. The URL for this profile is
`https://haddowg.github.io/json-api/profiles/relationship-counts/`.

A relationship's linkage tells a client *which* resources are related, but not
*how many* there are when the linkage is not fully materialised — a to-many
relationship rendered as links-only, or paginated, does not reveal the size of its
set. A client that needs the count ("the article, and how many comments it has")
must otherwise fetch the whole related collection and count it, or page to the end.

This profile defines a single query-parameter family, `withCount`, that lets a
client ask for the **size of a relationship's set alongside the primary resource**,
naming the relationships to count. The server returns each count as a `total`
member on the named relationship object's `meta`. For example:

```http
GET /articles/1?withCount=comments
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/relationship-counts/"
```

renders article 1 with `data.relationships.comments.meta.total` set to the number
of comments, in one request and without materialising the comments.

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here. This is the same
interpretation the JSON:API specification applies to these key words; see its
[conventions](https://jsonapi.org/format/#conventions).

## Specification

### Concepts

#### Countable relationships

Counting is a property of a **to-many** relationship, and a server decides which of
its relationships are countable. A relationship that the server has not made
countable cannot be counted through this profile (see
[Error Cases](#error-cases)). A to-one relationship is never countable — its
linkage is a single resource identifier, not a set.

#### The count reflects the rendered set

The `total` a server reports **MUST** equal the size of the same set the
relationship's related resource collection would return for the request — that is,
it **MUST** honour any filtering the server applies to that relationship (default
or request-supplied). It is the count of the *filtered* set, not of raw membership,
so it agrees with the `total` the relationship's own related-collection endpoint
would report for an equivalent request.

### Query Parameters

This profile reserves one
[implementation-specific query-parameter family](https://jsonapi.org/format/1.1/#query-parameters-custom):

| Family base | Role |
| --- | --- |
| `withCount` | canonical |

`withCount` is a flat, comma-separated list of relationship names — the same shape
as the [`include`](https://jsonapi.org/format/1.1/#fetching-includes) parameter,
but each name is a relationship of the **primary** resource to be counted:

```
withCount=<relationship-name>[,<relationship-name>…]
```

Each named relationship that the primary resource exposes as countable is counted;
order is not significant and duplicate names are equivalent to a single mention. A
named relationship that is not countable, is a to-one, or does not exist is an error
(see [Error Cases](#error-cases)).

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
and on a related resource's relationship object. A relationship **not** named in
`withCount` **MUST NOT** carry a `total`, even when it is countable: the count is
gated by the request, not emitted by default.

#### Advertising an applied profile

When a server applies this profile to a response, it **MUST** advertise the
profile as the base specification requires: the profile URI **MUST** be present in
the `profile` parameter of the response `Content-Type` media type and in the
document's top-level `jsonapi.profile` array.

### Document Structure

This profile reserves the `total` member of a **relationship object's** `meta`
object. When present, `total` **MUST** be a non-negative integer giving the size of
that relationship's (filtered) set. The profile reserves no other document members
and adds nothing else to the response body.

### Error Cases

Every error defined by this profile is reported as a `400 Bad Request`, following
the base specification's rules for
[processing errors](https://jsonapi.org/format/1.1/#errors-processing). The
response document **MUST** contain an
[error object](https://jsonapi.org/format/1.1/#error-objects) whose `source.parameter`
is `withCount`.

A `withCount` that names a relationship which is **not countable**, names a
**to-one** relationship, or names a relationship that **does not exist** on the
primary resource is a `400 Bad Request`. A server validates the named set up front,
against the relationships the primary resource exposes as countable, before
rendering.

## Notes

- `withCount=<rel>` reports the count that
  `GET /<primary>/<id>/<rel>` (the relationship's related resource collection) would
  page under the same filtering, folded onto the primary response as
  `relationships.<rel>.meta.total` — without materialising the related collection.
