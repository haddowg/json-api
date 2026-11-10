# haddowg/json-api

A framework-agnostic, server-side PHP library for producing and consuming
[JSON:API 1.1](https://jsonapi.org/format/1.1/) documents and projecting an OpenAPI 3.1 description
of them. This glossary fixes the words the library uses where they differ from generic terms or
from the spec's own wording.

## Language

### Declaring a resource type

**Resource**:
A consumer-declared class that lists a type's **Fields** once and is thereby both the type's
**Serializer** and its **Hydrator**.
_Avoid_: schema, model, entity, and "resource" for the wire value (that is a *resource object*)

**Field**:
A built, read-only declaration of one member of a **Resource**: an attribute or a **Relation**,
with its **Constraints**.
_Avoid_: property, column

**Field builder**:
The mutable object an author chains to declare a **Field** (what `Str::make()` returns), which the
**Resource** builds into the **Field** before use.
_Avoid_: field (for the builder)

**Relation**:
A **Field** that links to another resource type (belongs-to, has-many, polymorphic).
_Avoid_: association, and bare "relationship", which also names the relationship object a
**Serializer** emits and the linkage parsed from a request body

**Constraint**:
Validation metadata attached to a **Field** or **Filter**; core never executes it.
_Avoid_: rule, assertion

**Value type**:
The JSON type a written value of a **Field** must have, the one its write schema documents.
A value without it is refused, never cast.
_Avoid_: cast type, coercion

**Union**:
A **Field** whose value is one of several JSON primitive types, or a list of them, each type
taken by one unnamed member field. The value's JSON type picks the member on a write and its
PHP type on a read.
_Avoid_: variant (that is a `OneOf` shape), mixed field, any-type field

**Enum field**:
A **Field** declared with `enum()`, or `in()` over one backed enum's cases. A write hydrates
its value as the enum's case, unless the value is nested in a JSON column, where it keeps the
backing value, and a read renders a case as its backing value.
_Avoid_: enum cast, enum type

**Named shape**:
A composite **Field**'s value shape given a component name with `component()`, which the
OpenAPI projection emits once and every **Field** declaring it references, keeping its own
description, example, deprecation, presence and `null`. The shape's write forms take
`<Name>Create` and `<Name>Update` where they differ from the read form.
_Avoid_: shared schema, schema alias, reusable type

**Declared link**:
A named member of a resource object's `links`, declared on the **Resource** beside its fields so
the OpenAPI projection can describe it.
_Avoid_: custom link, extra link

**Action link**:
A **Resource**'s declaration that one of its type's custom actions renders as a member of its
`links`, with an optional condition and description; the href comes from the action.
_Avoid_: conditional link

**Related-only type**:
A type that serves no endpoint of its own, reached only as another type's related or
included resources or as a custom action's request document. Its resources and relationship
objects render no links to its own endpoints.
_Avoid_: embedded type, nested type, endpointless type

**Serializer**:
The output direction: maps a domain value to a resource object.
_Avoid_: transformer, normalizer

**Hydrator**:
The input direction: maps an incoming document onto a domain object.
_Avoid_: deserializer, denormalizer, mapper

### Querying a collection

**Filter**:
Metadata describing a `filter[<key>]` a type accepts.
_Avoid_: scope, criteria

**Action filters**:
The **Filters** a collection-scope custom action takes from its mount type, all of them or a
named subset, applied the way a collection fetch applies them.
_Avoid_: action query, action criteria

**Filter group**:
A composite **Filter** (`WhereAll` / `WhereAny`) that combines child filters with AND / OR under
one `filter[<key>]` and passes its request value to every child.
_Avoid_: boolean filter, and/or grouping

**Fixed value**:
A **Filter** value pinned by the author, so the request value is ignored and the key is a presence
trigger.
_Avoid_: default (a default is a value the client can override)

**Filter family**:
A **Filter** that answers every key starting with its prefix and a dot (`value.cap`), resolving the
suffix to a concrete **Filter** per request by the same rule as a **Sort family**.
_Avoid_: wildcard filter, dynamic filter

**List filter**:
A **Filter** declared with `acceptsList()`, whose value is a list sent as `filter[<key>][]` once per
member, or as one delimited string unless declared `delimited: false`.
_Avoid_: array filter, multi-value filter

**Relationship leaf**:
The last segment of a traversal **Filter**'s dotted path when it names a monomorphic
**Relation** rather than a field, so the filter compares the related resource's id, decoded
from the wire id through the related type's encoder.
_Avoid_: relation filter, id path

**Sort**:
Metadata describing a sort key a type accepts.

**Sort family**:
A **Sort** that answers every key starting with its prefix and a dot (`value.cap`, `value.end_date`),
resolving the suffix to a concrete **Sort** per request. Never matches the bare prefix.
_Avoid_: wildcard sort, dynamic sort

**Link row**:
The stored row an identified polymorphic to-many reads one member's type and id from. Its pivot
fields render as the member's `meta.pivot`, and its endpoints filter and sort over them.
_Avoid_: join row (for a polymorphic relation), morph pivot

**Link-row fields**:
The declaration of the fields of a **Link row** that point at its owner and hold its member's
stored type and id, with an optional map from stored type to JSON:API type. It lets a data layer
query the rows itself, under the counting, narrowing and tiebreak rules core sets.
_Avoid_: morph columns, link-row columns

**Link-row write plan**:
The deletes, temporary positions, updates and inserts one relationship write makes on the
**Link rows** of a relation that declares **Link-row fields**, in the order a data layer
applies them.
_Avoid_: sync plan

**Position**:
The stored integer a positioned to-many **Relation** keeps per member, set by the server from the
member's place in the linkage and never by a client.
_Avoid_: rank, sort order

**Default read order**:
The **Sort** order a to-many **Relation** reads its members in when a request names none. Its
own `defaultSort()` when declared, else its **Position** order, else the related type's.
_Avoid_: relation default sort, natural order

**Keyset sort**:
A custom **Sort** that can order a cursor page because it reports the value a row carries for the
cursor token.

**Adapter**:
Consumer-provided code that executes **Filter** and **Sort** metadata against a real data store.
_Avoid_: bare "handler" (see **Operation handler**)

**Paginator**:
A strategy that reads the request's `page[…]` parameters and produces a **Page**.

**Multi-paginator**:
A **Paginator** offering a menu of strategies, from which the client picks one per request with
`page[kind]`.
_Avoid_: pagination mode, page type

**Paginator kind**:
The identifier a **Paginator** declares (`page`, `offset`, `cursor`, `fixed` or a custom string),
used as its `page[kind]` value in a **Multi-paginator**.

**Window**:
The slice of a collection a **Paginator** asks the data layer to fetch (an offset and limit, or
cursor boundaries and a limit).

**Page**:
The value object holding one slice of results with its pagination links and meta.

### The request lifecycle

**Server**:
The immutable configuration root of one API version: registered **Resources** and **Profiles**,
base URI, encoding options, factories and middleware.

**Operation**:
A transport-independent statement of intent: fetch, create, update, delete, fetch related, a
relationship mutation or a custom action.
_Avoid_: command, request

**Target**:
What an **Operation** acts on: a type, optionally an id, and optionally a relation name.

**Operation handler**:
Consumer code that performs an **Operation** and returns a **Response**.
_Avoid_: bare "handler" (a PSR-15 request handler is the HTTP chain terminus)

**Operation key**:
The name of one operation a type exposes, independent of its route: its kind, the relation or
action it applies to, its method and an action's declared safety (`fetchRelated.author`).
_Avoid_: operationId (that also names the type)

**Operation catalogue**:
The list of operations a server exposes and the scopes each needs, as offered to clients.
_Avoid_: capabilities endpoint

**Scope table**:
The resolved map from each **Operation key** to the OAuth scopes it requires, shared by
enforcement and the OpenAPI projection.

**Profile**:
A JSON:API profile: an advisory, URI-named extension of document semantics that a server may
ignore.

**Document**:
A complete top-level JSON:API payload.

**Response**:
The value object an **Operation handler** returns, before it is rendered to an HTTP message.

### Errors

**Error code**:
The stable, machine-readable identifier of an error kind (`RESOURCE_NOT_FOUND`) that a client
matches on.
_Avoid_: error type, and "status" (the HTTP status is separate)

**Error catalogue**:
The set of error kinds a server can emit, each with its **Error code**: core's own plus those an
application contributes.
_Avoid_: error registry, error map

**Error descriptor**:
What an error kind states before it is raised: its **Error code**, status, default title,
placeholder shape, `source` member and **Error feature**.

**Error feature**:
The server capability an error kind depends on (atomic operations, cursor pagination, writes, …).

**Operation error document**:
The error document one operation answers a status with when it raises codes scoped to it, named
for its type, operation and status (`ArticlesUpdate409`) rather than for the codes it offers.
_Avoid_: narrowed response, scoped response

**Message template**:
An error's `title` or `detail` as a translatable string with `{placeholder}` slots, filled per
occurrence from the error's context.

### OpenAPI

**Projection**:
The OpenAPI document (and its diagnostics) that core derives from a **Server**'s metadata.
_Avoid_: generated spec, export

**Generator contract**:
The integer stamped on every **Projection** that tells a code generator which document structure
it is reading.
_Avoid_: spec version, schema version
