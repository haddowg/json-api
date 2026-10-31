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

**Declared link**:
A named member of a resource object's `links`, declared on the **Resource** beside its fields so
the OpenAPI projection can describe it.
_Avoid_: custom link, extra link

**Action link**:
A **Resource**'s declaration that one of its type's custom actions renders as a member of its
`links`, with an optional condition and description; the href comes from the action.
_Avoid_: conditional link

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

**Filter group**:
A composite **Filter** (`WhereAll` / `WhereAny`) that combines child filters with AND / OR under
one `filter[<key>]` and passes its request value to every child.
_Avoid_: boolean filter, and/or grouping

**Fixed value**:
A **Filter** value pinned by the author, so the request value is ignored and the key is a presence
trigger.
_Avoid_: default (a default is a value the client can override)

**Sort**:
Metadata describing a sort key a type accepts.

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
