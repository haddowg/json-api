# "Nested Fieldsets" Profile

## Introduction

This is the specification of a
[profile](https://jsonapi.org/format/1.1/#profiles) for the JSON:API
specification. The URL for this profile is
`https://haddowg.github.io/json-api/profiles/nested-fieldsets/`.

A [sparse fieldset](https://jsonapi.org/format/1.1/#fetching-sparse-fieldsets)
selects whole fields. A `person` resource has an `address` attribute that is an object
containing multiple address fields like street, county, postcode etc. A client needing only
the postcode has to request the whole address object.

This profile lets a `fields[TYPE]` member name one member of an attribute's object as
`<attribute>.<key>`, so a sparse fieldset reaches inside the attribute. The members it names
are the attribute's **nested fieldset**:

```http
GET /people?fields[people]=name,address.postcode
Accept: application/vnd.api+json
```

returns each person's `name`, and an `address` object holding only `postcode`:

```json
"attributes": {
  "name": "Ada Lovelace",
  "address": { "postcode": "SW1A 1AA" }
}
```

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here. This is the same
interpretation the JSON:API specification applies to these key words; see its
[conventions](https://jsonapi.org/format/#conventions).

## Specification

### Concepts

#### Attribute with a nested fieldset

An attribute whose value is an object, and whose members the server lets a client
select one at a time, **has a nested fieldset**, and so does a list of such objects
when the server supports the item wildcard below. The server decides which attributes
have one, and which member names of each a client may select.

#### Nested fieldset member

A **nested fieldset member** is a `fields[TYPE]` member of the form `<attribute>.<key>`:
the name of an attribute that has a nested fieldset, a full stop (U+002E), and the name
of one member of that attribute's object. JSON:API member names contain no full stop, so
a nested fieldset member never collides with a field name, and a key never contains one.
The nested fieldset members of a request that name one attribute make up that
attribute's **nested fieldset**.

A server **MAY** let a nested fieldset member address a member of a nested object, as
`<attribute>.<key>.<key>` and so on, one key per level, where the value at each level
is an object whose members the server lets a client select. `address.geo.lat` selects
the `lat` member of the `geo` object inside `address`.

A server **MAY** let a nested fieldset member address the items of a list with an **item wildcard**,
the segment `*` (U+002A) after the name of a list whose items are objects the server lets
a client select from. `lines.*.sku` selects the `sku` member of every item of the `lines`
list. A path continues through nested objects and lists the same way, so
`lines.*.price.amount` and `groups.*.rows.*.label` are nested fieldset members too, and a list inside
an object is reached as `shipping.parcels.*.weight`. A path that ends in `*` names the list
itself, so `lines.*` with nothing after it **MUST** be processed exactly as `lines`, and
`shipping.parcels.*` exactly as `shipping.parcels`. A list the server does not let a client select from, such as a list
of strings, is selected whole by its name. JSON:API member names contain no asterisk, so
`*` never collides with a key.

An object may take one of several forms, each with its own members, such as a hero that is
an image or a video, or the blocks of text and images in a list. A server **MAY** let a
client select from such an object wherever it appears, deciding for each form which of its
members a client may select. A key is accepted when the server lets a client select it
from one of the forms, and a path continues inside the forms that accept it.

A nested fieldset member addresses no single item. A segment after a list that is not `*`, such as
the index in `lines.0`, is not part of this profile.

### Query Parameters

This profile reserves no query parameter. It widens the members the base
specification's `fields[TYPE]` parameter accepts:

```
fields[TYPE] = <member>,<member>,...
<member>     = <field-name> | <attribute>.<segment> | <attribute>.<segment>.<segment> ...
<segment>    = <key> | *
```

A `*` segment follows only the name of a list. The segments after it address members of
each item.

### Processing

#### Profile negotiation

A client does **not** have to ask for this profile. A server that implements it
**MUST** process nested fieldset members whether or not the request negotiated the profile, so
a request carrying only `Accept: application/vnd.api+json` may use them.

#### Rendering a nested fieldset

For each resource of type `TYPE` the response renders:

- An attribute named by a nested fieldset member, and not listed whole, **MUST** be rendered as
  an object holding only the selected members the resource's value has. A selected
  member the value does not have is left out, so the object may be empty.
- An attribute listed whole **MUST** be rendered whole, whatever nested fieldset
  members also name it.
- Every other field follows the base specification: a field `fields[TYPE]` does not
  list is left out.

A nested fieldset member addressing a nested object selects only the members along its
path. Each object on the path **MUST** be rendered holding only the members nested
fieldset members select from it, and nothing else of it:

```http
GET /people?fields[people]=address.postcode,address.geo.lat
```

```json
"attributes": {
  "address": { "postcode": "SW1A 1AA", "geo": { "lat": 51.501 } }
}
```

The street is left out of `address`, and every member of `geo` but `lat` is left out
of `geo`. The rule for an attribute listed whole applies at every level: a nested fieldset member
naming a nested object whole, such as `address.geo`, renders all of that object,
whatever nested fieldset members also address members inside it, so
`fields[people]=address.geo,address.geo.lat` renders `address` holding the whole of
`geo` and nothing else.

A nested fieldset member with an item wildcard selects from every item of the list. The list **MUST**
be rendered with all of its items, in the order the whole list renders them, each item
rendered as an object holding only the members the nested fieldset members select from it, by the
rules above. An item that does not have a selected member, as the whole list would render
it, is rendered without it, so an item holding none of them is rendered as an empty
object, and an empty list is rendered as an empty list:

```http
GET /orders?fields[orders]=lines.*.sku
```

```json
"attributes": {
  "lines": [{ "sku": "A1" }, { "sku": "B2" }, {}]
}
```

An object that takes one of several forms, on its own or as an item of a list, **MUST** be
rendered holding only the selected members the server lets a client select from its form,
by the rules above, and the member that names its form, when the form is named by a
member:

```http
GET /pages?fields[pages]=blocks.*.title
```

```json
"attributes": {
  "blocks": [{ "type": "text", "title": "Intro" }, { "type": "image" }, { "type": "divider" }]
}
```

The rule for a field listed whole applies to the items as well: `lines,lines.*.sku` and
`lines.*,lines.*.sku` both render every item of `lines` whole.

A request with no `fields[TYPE]` parameter is processed as the base specification
describes. The nested fieldsets apply to every resource of type `TYPE` the response
renders, in primary data and in `included` alike.

#### Writes

This profile changes nothing about a request body. A write that answers with the
resource renders it under the request's `fields[TYPE]`, nested fieldsets included.

#### Advertising an applied profile

When a request carries a nested fieldset member the server accepts, for an attribute it does not
also list whole, the server **MUST** advertise the profile on the response as the
base specification requires: the profile URI **MUST** be present in the `profile`
parameter of the response `Content-Type` media type and in the document's top-level
`jsonapi.profile`. A nested fieldset member that names an attribute whole, as `lines.*`
does, lists that attribute whole and does not count. When the request carries no such
nested fieldset member, the
server **MUST NOT** add the profile to a response the client did not negotiate it for.

### Document Structure

This profile reserves no document members. A selected attribute keeps the shape
its whole value has, with fewer members, and so does each nested object a nested
fieldset member selects from. A list selected through an item wildcard keeps its length, and each item
keeps the shape it has whole, with fewer members.

### Error Cases

A nested fieldset member the server does not accept is an unrecognized `fields[TYPE]` member,
and the server handles it as it handles any other: a server that rejects unknown
fieldset members responds `400 Bad Request` with an
[error object](https://jsonapi.org/format/1.1/#error-objects) whose
`source.parameter` is `fields`, and a server that ignores them renders nothing for
it. A nested fieldset member is not accepted when:

- its attribute does not exist, is not an attribute, or has no nested fieldset;
- the server does not allow that key of the attribute;
- it continues past a key whose value the server does not let a client select from,
  such as a string, or an object or a list the server returns only whole;
- it uses `*` on a member that is not a list whose items the server lets a client
  select from: a scalar, an object, a list of scalars, or a list of objects the server
  returns only whole;
- on an object that takes one of several forms, no form lets a client select its key or
  the rest of its path;
- it addresses an item of a list by anything but `*`, such as an index;
- one of its keys is empty.

## Notes

- The member list of `fields[TYPE]` in JSON:API 1.1 holds field names only. This
  profile is how a response tells a client that a `fields[TYPE]` parameter selected
  less than whole fields.
- A server describing its API in OpenAPI can document the nested fieldset members it accepts
  on each `fields[TYPE]` parameter, as
  [this library's projection](../openapi.md#nested-fieldsets) does.
