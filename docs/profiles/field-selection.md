# "Field Selection" Profile

## Introduction

This is the specification of a
[profile](https://jsonapi.org/format/1.1/#profiles) for the JSON:API
specification. The URL for this profile is
`https://haddowg.github.io/json-api/profiles/field-selection/`.

A [sparse fieldset](https://jsonapi.org/format/1.1/#fetching-sparse-fieldsets)
selects whole fields. An attribute whose value is an object with many members, such
as a set of named measures, comes back whole even when the client shows three of
them. A data table with one column per member is the usual case: each column is one
member of the same object attribute, and the table reads a handful of them for every
row.

This profile lets a `fields[TYPE]` parameter name one member of an object attribute
as `<attribute>.<key>`. For example:

```http
GET /products?fields[products]=name,values.cap,values.countries
Accept: application/vnd.api+json
```

returns each product's `name`, and a `values` object holding only its `cap` and
`countries` members.

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here. This is the same
interpretation the JSON:API specification applies to these key words; see its
[conventions](https://jsonapi.org/format/#conventions).

## Specification

### Concepts

#### Selectable attribute

An attribute whose value is an object, and whose members the server lets a client
select one at a time, is a **selectable attribute**. The server decides which
attributes are selectable, and which member names of each a client may select.

#### Key member

A **key member** is a `fields[TYPE]` member of the form `<attribute>.<key>`: the
name of a selectable attribute, a full stop (U+002E), and the name of one member of
that attribute's object, taken whole up to the next comma. JSON:API member names
contain no full stop, so a key member never collides with a field name.

A key member selects one member of the object. It never addresses anything deeper:
when the member's value is itself an object or a list of objects, such as a
repeating group of rows, the key member selects that value whole.

### Query Parameters

This profile reserves no query parameter. It widens the members the base
specification's `fields[TYPE]` parameter accepts:

```
fields[TYPE] = <member>,<member>,...
<member>     = <field-name> | <attribute>.<key>
```

### Processing

#### Profile negotiation

A client does **not** have to ask for this profile. A server that implements it
**MUST** process key members whether or not the request negotiated the profile, so
a request carrying only `Accept: application/vnd.api+json` may use them.

#### Rendering a selection

For each resource of type `TYPE` the response renders:

- An attribute named by a key member, and not listed whole, **MUST** be rendered as
  an object holding only the selected members the resource's value has. A selected
  member the value does not have is left out, so the object may be empty.
- An attribute listed whole **MUST** be rendered whole, whatever key members also
  name it.
- Every other field follows the base specification: a field `fields[TYPE]` does not
  list is left out.

A request with no `fields[TYPE]` parameter is processed as the base specification
describes. The selection applies to every resource of type `TYPE` the response
renders, in primary data and in `included` alike.

#### Writes

This profile changes nothing about a request body. A write that answers with the
resource renders it under the request's `fields[TYPE]`, selection included.

#### Advertising an applied profile

When a request carries a key member the server accepts, for an attribute it does not
also list whole, the server **MUST** advertise the profile on the response as the
base specification requires: the profile URI **MUST** be present in the `profile`
parameter of the response `Content-Type` media type and in the document's top-level
`jsonapi.profile`. When the request carries no such key member, the server **MUST
NOT** add the profile to a response the client did not negotiate it for.

### Document Structure

This profile reserves no document members. A selected attribute keeps the shape
its whole value has, with fewer members.

### Error Cases

A key member the server does not accept is an unrecognized `fields[TYPE]` member,
and the server handles it as it handles any other: a server that rejects unknown
fieldset members responds `400 Bad Request` with an
[error object](https://jsonapi.org/format/1.1/#error-objects) whose
`source.parameter` is `fields`, and a server that ignores them renders nothing for
it. A key member is not accepted when:

- its attribute does not exist, is not an attribute, or is not selectable;
- the server does not allow that key of the attribute;
- its key is empty.

## Notes

- The member list of `fields[TYPE]` in JSON:API 1.1 holds field names only. This
  profile is how a response tells a client that a `fields[TYPE]` parameter selected
  less than whole fields.
- A server describing its API in OpenAPI can document the key members it accepts
  on each `fields[TYPE]` parameter, as
  [this library's projection](../openapi.md#selecting-keys-of-an-attribute) does.
