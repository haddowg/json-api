# haddowg/json-api

A server-side library for producing and consuming [JSON:API 1.1](https://jsonapi.org/format/1.1/)
documents in PHP. This glossary fixes the library's vocabulary where it diverges
from generic terms or from the spec's own wording — so "Resource", "Relation",
and "Handler" mean one thing here, not three.

## Language

### Defining a resource type

**Resource**:
A consumer-declared class that lists a type's fields once and thereby acts as
both its serializer and its hydrator.
_Avoid_: schema, model, entity. (Distinct from a *resource object* — see Flagged ambiguities.)

**Field**:
A single declared member of a **Resource** — an attribute or a **Relation**.

**Relation**:
A **Field** that links to another resource type (belongs-to, has-many, polymorphic).
_Avoid_: association. (The output-emitted and input-parsed forms are distinct — see Flagged ambiguities.)

**Constraint**:
Inert validation metadata attached to a **Field**; the core never executes it.
_Avoid_: rule, assertion.

**Serializer**:
Maps a domain value to a wire resource — the output direction.
_Avoid_: transformer, normalizer.

**Hydrator**:
Maps an incoming document into a domain object — the input direction.
_Avoid_: deserializer, denormalizer, mapper.

### Querying a collection

**Filter**:
Metadata describing a filter a type accepts; an **Adapter** executes it.
_Avoid_: scope, criteria.

**Sort**:
Metadata describing a sort key a type accepts.

**Paginator**:
A strategy that reads the request's `page[…]` parameters and produces a **Page**.

**Page**:
The value object holding one slice of results together with its pagination links and meta.

**Adapter**:
Consumer-provided code that executes **Filter**/**Sort** metadata against a real
data store — the bridge between inert metadata and an actual query.
_Avoid_: handler (unqualified — see Flagged ambiguities).

### The request lifecycle

**Operation**:
A verb-agnostic statement of intent (fetch a resource, create, update, delete, fetch related…).
_Avoid_: action, command.

**Target**:
What an **Operation** acts on — a type, optionally an id and a relationship name.

**Profile**:
A JSON:API 1.1 profile — an advisory, URI-named extension of document semantics
that a server may ignore if it does not recognise it.

**Server**:
The immutable, per-API-version configuration root: resource registry, profiles,
base URI, encoding options, PSR-17 factories, and middleware.

**Document**:
A complete top-level JSON:API payload (data/errors/meta + links).

**Response**:
The public value object a handler returns (data, error, meta, related, identifier)
before it is rendered to a PSR-7 message.

## Relationships

- A **Server** registers many **Resources** and **Profiles**.
- A **Resource** declares many **Fields**, and *is* both a **Serializer** and a **Hydrator**.
- A **Field** may be a **Relation** and may carry **Constraints**.
- An **Operation** names a **Target** and is handled to produce a **Response**.
- A **Paginator** produces a **Page**; a **Filter** or **Sort** is executed by an **Adapter**.

## Example dialogue

> **Dev:** "A `posts` **Resource** declares a `title` **Field** and an `author`
> **Relation** — does that one class also handle an incoming `PATCH` body?"
> **Maintainer:** "Yes. A **Resource** is both **Serializer** and **Hydrator**;
> the same field list drives output and input. You only write a standalone
> **Serializer** or **Hydrator** when field-walking isn't enough."
> **Dev:** "And if the client sends `filter[status]=draft`?"
> **Maintainer:** "The **Resource** declares a `status` **Filter** — that's just
> metadata. Your **Adapter** is what turns it into an actual query."

## Flagged ambiguities

- **"Relationship"** meant three things — resolved into distinct concepts: the
  **Relation** *field* (the declaration), the relationship a **Serializer**
  *emits* on output, and the relationship linkage *parsed from a request body* on
  input. These are separate types; name the direction when it isn't obvious from context.
- **"Handler"** was overloaded — resolved: a **Filter**/**Sort** handler executes
  query metadata (the **Adapter** side); an *operation handler* holds the
  consumer's business logic for an **Operation**; a PSR-15 *request handler* is
  the HTTP chain terminus. Never use bare "handler" without the qualifier.
- **"Resource"** — resolved: the glossary term means the consumer's fluent
  **Resource** *class*. The thing in the wire document is always a *resource object*.
- **"Schema"** — avoid as a synonym for **Resource**; reserve "schema" for JSON
  Schema validation documents.
