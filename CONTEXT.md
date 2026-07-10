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

**Filter group**:
A composite **Filter** (`WhereAll` / `WhereAny`) that combines child filters with
boolean AND / OR under one `filter[<key>]` key. Composed by the author — a client
cannot assemble arbitrary boolean algebra. The group's request value is passed to
every child, so it either fans one value across columns (search) or toggles a set
of **fixed**-value conditions.
_Avoid_: boolean filter, and/or grouping (implies the declined client-driven model).

**Fixed value**:
A **Filter** value pinned by the author (`->fixed(…)`) so the request value is
ignored and the key becomes a presence trigger. Distinct from a **default**, which
the client can override.

**Sort**:
Metadata describing a sort key a type accepts.

**Paginator**:
A strategy that reads the request's `page[…]` parameters and produces a **Page**.

**Multi-paginator**:
A **Paginator** that offers several strategies at once; the client selects one per
request with `page[kind]=<kind>`, and an absent `page` falls back to the author's
declared default. The author composes the menu — a client cannot invent a strategy.
_Avoid_: pagination mode, page type.

**Paginator kind**:
The free-form string identifier a **Paginator** declares (its `kind()`) — the
`page[kind]` discriminator value that names it in a **Multi-paginator** menu (and the
OpenAPI `oneOf` branch `const`). Built-ins name themselves (`page`, `offset`,
`cursor`, `fixed`); a custom paginator declares its own (`->withKind('…')`).
Each **Paginator** also self-describes its `page[…]` **Schema**, so the projector
emits a strategy's real parameters without a central switch.

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

### Errors

**Error catalogue**:
The fixed set of error kinds the library can emit — one typed exception per kind,
each carrying its own error data. _Avoid_: error registry, error map.

**Error code**:
The stable, machine-readable identifier of an error kind (`RESOURCE_NOT_FOUND`) —
the contract a client codes against. Never localized, never overridden; the key by
which human copy is resolved. Distinct from **status** (the HTTP status) and from
the **title**/**detail** copy.

**Message template**:
An error's human-readable **title** or **detail** as a translatable string with
`{placeholder}` slots, filled per occurrence from a **context** of locale-invariant
values (a media type, an id). Localization resolves the template by **Error code**;
the placeholders are interpolated *after*.

## Relationships

- A **Server** registers many **Resources** and **Profiles**.
- A **Resource** declares many **Fields**, and *is* both a **Serializer** and a **Hydrator**.
- A **Field** may be a **Relation** and may carry **Constraints**.
- An **Operation** names a **Target** and is handled to produce a **Response**.
- A **Paginator** produces a **Page**; a **Filter** or **Sort** is executed by an **Adapter**.
- An **Error catalogue** entry is identified by its **Error code**; its **title**/**detail** are **Message templates** filled from an occurrence **context**.

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
- **"Field"** — resolved: the glossary term is the **readonly value object** the
  engine walks — it serializes/hydrates and carries **Constraints**. The mutable,
  fluent object an author chains (what `Str::make()` returns) is a **field
  builder**; a `fields()` entry may be either, and the resource **builds** any
  builder into its **Field** before use. Autocomplete on a builder shows only
  authoring methods; the **Field** exposes only the consumption surface. (Amends
  [ADR 0003](docs/adr/0003-immutable-value-objects-with-carve-outs.md): the builder
  stays mutable, but it now *produces* a readonly **Field** rather than *being* one.)
