# Core docs — Phase 1A deliverable: IA, capability matrix & example-app spec

> Workflow output (17 agents: 8 capability cartographers + 7 doc auditors → synthesis → adversarial IA critique). For maintainer sign-off before Phase 2 (build the example app) and Phase 3 (write the docs).

## Reading-journey rationale

## Rationale and reading journey

The current 27-file doc set is a flat, reference-first, internals-heavy reference that drifted from the frozen code (MorphToMany undocumented; the whole AbstractRelation policy surface, the batch SortHandler signature, PaginatorInterface::window(), withStatus(), NoContentResponse, RelatedResponse::fromPage(), AdditionProhibited/FilterParamUnrecognized all missing or wrong). The greenfield IA keeps EVERY user-facing capability that audit covered but re-sequences it as a progressive-disclosure reading journey backed by a single worked example app — a music catalog (artists, albums, tracks, playlists, users, favorites, library) whose relationship topology exercises every relation type once: album→artist (BelongsTo), artist→featuredAlbum (HasOne), album→tracks (HasMany), track↔playlists (BelongsToMany + pivot), favorite→favoritable (MorphTo), library→items (MorphToMany).

The journey has six arcs, each a section: (1) Getting started — what/why, then one end-to-end music-catalog endpoint built from scratch (README/getting-started/concepts/architecture content, re-themed and de-internalised). (2) Defining resources — the type model and the field DSL, where the bulk of new per-field-type depth lives: one shared-surface page, then one page PER field type (each showing only its delta over the shared surface, per the detail-each-field-type mandate), a relations page, a dedicated constraints page (the summary TABLE), and an id/client-id page. (3) Querying — filters, sorts, pagination, includes & sparse fieldsets. (4) Serialization & hydration control — the escape-hatch tiers from cheapest (field hooks) to fullest (custom serializer/hydrator, polymorphic serializer), and the capability-composition story. (5) Request/response lifecycle — server, operations & dispatch, responses, content negotiation, errors & the exception catalogue, middleware. (6) Cross-cutting — adapters (the metadata/execution split), optional schema validation, profiles, links & meta, security, testing, and a spec-compliance ledger.

Each capability page opens with the simplest real music-catalog use, then layers options, then branches to advanced/nuance (worked examples only where a capability has a meaningful nuance — the four field hooks, computed/hidden fields, closure date bounds, the Map vs ArrayHash contrast, singular filters, the batch sort contract, the window() push-down loop, the two dispatch paths, the 415/406 asymmetry, polymorphic rendering, error-status derivation). Reference tables come AFTER the worked example on every page that has one (constraints summary, exception catalogue, paginator comparison, the response/withers matrix). Internals (Internal\ namespaces, @internal classes like MediaType/QueryParam/the transformer engine/document classes) are excluded from capability claims and only ever named as where-it-lives footnotes in the architecture page. The example app is the single source of truth: every snippet is extracted from a CI-run test over the in-memory data layer, so docs cannot drift — the same discipline the current getting-started page enforces, carried to the whole set.

The capability-composition thesis (a type = serializer + hydrator + relations + provider + persister in any combination, AbstractResource is pure sugar) is introduced gently — resources.md presents AbstractResource as the 90% on-ramp and forward-links to capability-composition.md, which owns the standalone-registration / override-resolution story so the on-ramp page stays uncluttered.

## Proposed information architecture (32 pages, 6 sections)


### Getting started


**`index.md`** — haddowg/json-api — a server-side JSON:API 1.1 library for PHP  

*Role:* Repository/docs front door. Audience: an evaluator deciding whether to adopt. One-paragraph identity, goals, scope boundaries, install, a 12-line taste, and the map into the rest of the docs.  

*Outline (progressive disclosure):*

  - One-paragraph identity: server-side, framework- and storage-agnostic JSON:API 1.1 library for PHP 8.3/8.4/8.5

  - Pre-1.0 instability warning (breaking changes between 0.x minors, recorded in changelog)

  - The four goals: verifiable 1.1 compliance; first-class server-side profiles; a shipped PSR-15 middleware suite for the standard lifecycle; a stable production-suitable foundation

  - Out of scope: client-side, framework integrations (a separate Symfony bundle), migration tooling

  - Install: composer require haddowg/json-api (+ the not-yet-on-Packagist caveat, stated ONCE here); needs a PSR-7/PSR-17 impl (nyholm/psr7 in examples)

  - A minimal taste: an AlbumResource (Id + Str) + Server::make()->withPsr17()->register() — pointing forward, NOT a runnable copy-paste

  - Design-philosophy bullets: readonly value objects, enums, typed exception hierarchy, one field declaration drives BOTH serialize and hydrate, first-class profiles

  - Credits (woohoolabs/yin; fluent layer inspired by Laravel JSON:API), MIT dual-copyright licence

  - Docs map: links into Getting started then the six sections

*Capabilities:* Project identity & goals, Install & requirements, Pre-1.0 warning, Scope boundaries, Design philosophy, Credits & licence


**`getting-started.md`** — Getting started: your first music-catalog endpoint  

*Role:* The canonical end-to-end onboarding walkthrough. Audience: a first-time user. Builds a fetch+create albums endpoint from an empty project, end-to-end and test-verified.  

*Outline (progressive disclosure):*

  - The pieces YOU provide: a domain model (any object/array); a Resource class declaring fields; an operation handler (operation to response VO); a router mapping URL to Target

  - The pieces the LIBRARY provides: negotiation, body parsing, sparse fieldsets, includes, error rendering, encoding

  - Step 1 — the domain model + in-memory repository (Album)

  - Step 2 — AlbumResource extends AbstractResource: $type + fields() with Id + Str (one declaration drives serialize AND hydrate)

  - Step 3 — a music-catalog OperationHandler: match(true) over FetchResourceOperation (collection vs single via target()->hasId()) and CreateResourceOperation; reach types via context()->server narrowed to Server

  - Step 4 — a toy path-prefix router middleware attaching Operation\Target keyed by Target::class (honest real-routing-is-your-framework caveat)

  - Step 5 — wire the Server: Server::make()->withBaseUri()->withPsr17()->register(); ->withMiddleware([ErrorHandler, ContentNegotiation, RequestBodyParsing, router])->withHandler()

  - Step 6 — dispatch via $server->handle($request)

  - Three worked HTTP outcomes with explicit Accept/Content-Type headers: GET /albums/1 to 200; POST /albums (full request envelope) to 201 echoing the server-generated id; GET /albums/999 to 404 via ResourceNotFound

  - Where to go next: the section hub

*Capabilities:* AbstractResource minimal, OperationHandlerInterface pattern, match(true) dispatch, Target routing contract, Server wiring + middleware order, Server::handle(), DataResponse::fromResource/fromCollection, ErrorResponse::fromException, hydratorFor()->hydrate() on create, 201 via withStatus()


**`concepts.md`** — Core concepts: documents, resources, identifiers, links, errors  

*Role:* The JSON:API document-model vocabulary, concept-first (spec shape leads; the class is a where-it-lives footnote). Audience: anyone needing the shared mental model before the deeper pages.  

*Outline (progressive disclosure):*

  - The three meanings of resource: resource OBJECT (the {type,id,attributes,relationships} spec structure, emitted as a plain array — there is NO ResourceObject class) vs resource CLASS (an AbstractResource subclass) vs serializer/hydrator (the lower-level contracts); the 95% path writes a Resource class

  - Document: the top-level object carrying at most one of data OR errors plus optional meta/links/jsonapi/included; you produce one indirectly by returning a response VO (six of them); documents are internal machinery you never subclass

  - The three shared top-level members jsonapi/meta/links and their withers (withJsonApi/withMeta/withLinks)

  - How fields map to a resource object: Id to top-level id, attribute fields to attributes, relationship fields to relationships, type from static $type; sparse fieldsets + include applied by the engine

  - Resource identifier = {type, id-or-lid, optional meta}; the data of a relationship and the body of a /relationships endpoint

  - Local id (lid): the 1.1 forward-reference; parses/validates/flows to the hydrator but is NOT resolved back to a created resource within one request (the scope boundary)

  - Relationship concept: to-one data is one identifier or null, to-many is a list; empty linkage on input = clear; the two families (output builders vs input parsed-linkage VOs) you never touch directly with a Resource

  - Links: bare-string vs link-object form; grouped into keyed containers with a baseUri prepend; reserved relations + custom; pagination links auto-emitted by fromPage()

  - The jsonapi object (version defaults to 1.1) and meta (free-form, empty = omit)

  - Error object = one problem with optional id/status/code/title/detail/source/links/meta; ErrorSource locates the cause (pointer/parameter/header) — the body/query/header triad; usually reached as typed exceptions

*Capabilities:* Document model, Resource object vs class vs serializer disambiguation, Resource identifier + lid, Relationship concept (to-one/to-many, clear semantics), Links model + containers, jsonapi object, meta, Error object + ErrorSource triad, The six response VOs named


**`architecture.md`** — Architecture: how a request flows through the library  

*Role:* Traces a request end-to-end and names the responsible part at each step. Audience: a user wanting the system model + a contributor needing the internals map. The one page where internals are named (as a labelled aside).  

*Outline (progressive disclosure):*

  - Framing: the library is a PSR-15 application — PSR-7 in, PSR-7 out; everything between is composed from small replaceable parts

  - The Server is the immutable config root for ONE API version (registry incl. standalone serializer/hydrator + operation allow-lists, profiles, PSR-17 factories, default paginator, doc defaults, middleware list, inner handler); every with-er/register returns a new instance

  - Two dispatch entry points: handle() (full PSR-15 chain) vs dispatch() (an already-built operation, bypassing middleware)

  - A request-flow diagram

  - Stage 1 — the middleware chain (fold order; the wrap-once JsonApiRequest mechanism)

  - Stage 2 — the adapter: Psr7ToOperationHandlerAdapter reads the Target attribute and selects one of nine operations via OperationFactory's method-by-target-shape table; missing Target to 500

  - Stage 3 — the operation handler (PSR-7-free, match(true), reach types via context()->server)

  - Stage 4 — the serialization engine (the response VO renders to a plain-array document; serializer-free; owns included/sparse/dedup) — flagged @internal

  - Stage 5 — encoding (toPsrResponse json_encodes ONCE, at the end, via PSR-17, fixed Content-Type)

  - Why this shape: each concern replaceable; immutable Server to hold several, one per version

  - Aside: the @internal parts (document classes, transformer engine, MediaType scanner) named here and nowhere else in user docs

*Capabilities:* PSR-15 application model, Server as config root, handle() vs dispatch(), Request-flow stages, wrap-once JsonApiRequest, serializer-free engine (internals aside), Multi-server/versioning


### Defining resources


**`resources.md`** — Defining a resource  

*Role:* The AbstractResource on-ramp — declare a whole type once. Audience: the 90% user. Presents AbstractResource as the recommended entry point and forward-links the composed model.  

*Outline (progressive disclosure):*

  - The thesis: subclass AbstractResource, set $type, implement fields() — satisfies BOTH serializer and hydrator; 95% write no serializer/hydrator by hand

  - A minimal AlbumResource (Id + Str fields)

  - The note-on-names (spec resource object = a plain array, no ResourceObject class)

  - $type as the JSON:API type AND registry key; declaration order preserved in output

  - uriType / static $uriType: the URL path segment decoupled from $type (e.g. type track served at /tracks) — with the music example where they differ

  - The overridable-method contract TABLE: fields() (required), filters(), sorts(), pagination(), allSorts() (auto-derived from ->sortable() merged with sorts()), and the narrowing hooks (attributeFields/relationFields/relationNamed/idField)

  - How fields drive serialization (Id to id, attributes via accessor or hooks, relationships via the registry, hidden/notSparseField)

  - How fields drive hydration (id hook + generateId() v4-UUID default + acceptsClientGeneratedId() default false; attribute write; readOnly contexts; PATCH absence = no change)

  - Registration on a Server: register(class-string); lazy instantiation; duplicate-type LogicException; the registry IS the relationship resolver so all participating types must be registered

  - Relationships as fields (the BelongsTo/HasMany teaser to relations.md)

  - Field constraints are METADATA core never executes (to constraints.md, validation)

  - Branch: override just the serializer or just the hydrator, or skip the Resource entirely to capability-composition.md

*Capabilities:* AbstractResource, uriType/$uriType, Overridable methods (fields/filters/sorts/pagination/allSorts), Serialization walk, Hydration walk, generateId()/acceptsClientGeneratedId(), Registration & lazy instantiation, Narrowing hooks (attributeFields/relationFields/relationNamed/idField)


**`fields.md`** — Fields: the shared builder surface  

*Role:* The AbstractField fluent surface every attribute field inherits. Audience: anyone declaring fields. Documented ONCE here; per-type pages show only their delta.  

*Outline (progressive disclosure):*

  - Fields are mutable builders: every fluent method mutates-and-returns, so a field reads as one chained expression; one fields() entry drives BOTH serialize and hydrate

  - Naming/storage: make(name) (use it, not new); storedAs(column) (member name not equal to storage name); computed() (no backing column; pair with extractUsing); the framework-agnostic accessor order (public property then getXxx/setXxx then array key)

  - Visibility & query eligibility: hidden(), notSparseField(), sortable()

  - Read-only scoping: readOnly() / readOnlyOnCreate() / readOnlyOnUpdate() — gate HYDRATION (silently skipped)

  - Presence (gates VALIDATION): required() / requiredOnCreate() / requiredOnUpdate(); nullable(); and the create-vs-update semantics axis (PATCH absence = no change)

  - The two-axes-conflated callout: read-only (hydration gate) vs required* (validation gate)

  - Enumeration on every field: in(values) / notIn(values)

  - Context scoping: onCreate(builder) / onUpdate(builder) re-stamp every constraint in the closure

  - Composition & cross-field, available on EVERY field: constrain(...ConstraintInterface) (typed escape hatch, Context NOT re-stamped), sequentially(...), atLeastOneOf(...), when(condition, builder) (folds to one When, opaque to JSON Schema), compareWith(field, Comparison)

  - WORKED EXAMPLE (the most-misused part): the four hook closures — serializeUsing/extractUsing (read) vs deserializeUsing/fillUsing (write) — on a Track: computed displayTitle via extractUsing()+computed(); duration stored under a renamed column via storedAs()

  - Then per-field-type pages for the type-specific deltas (linked index)

*Capabilities:* AbstractField full surface, make/storedAs/computed, Accessor resolution order, hidden/notSparseField/sortable, readOnly scoping trio, required/nullable trio, in/notIn, onCreate/onUpdate, constrain/sequentially/atLeastOneOf/when/compareWith, The four hooks (serializeUsing/extractUsing/deserializeUsing/fillUsing)


**`field-types.md`** — Field types reference (per type)  

*Role:* One section per concrete field type, each showing only its delta over the shared surface (fields.md). Audience: a user picking the right field. Details EACH field type individually, as mandated.  

*Outline (progressive disclosure):*

  - Str: minLength/maxLength/pattern + the five format shortcuts email(strict=false)/url(allowedSchemes)/uuid(?version)/slug(?regex)/ip(?version); the equivalence note (Str::make()->email() equals Email::make()) stated ONCE here

  - Integer: min/max/exclusiveMin/exclusiveMax/multipleOf(int)/in(int[]); int cast both ways (track number, duration) — example: trackNumber

  - Decimal: same bounds accepting int|float; float cast (price, rating) — example: Album.averageRating

  - Boolean: no type-specific builders; bool cast — example: Track.explicit

  - DateTime: format(default ATOM)/before/after/between (DateTimeInterface|Closure)/useTimezone; WORKED EXAMPLE — Album.releasedAt with before(fn()=> new DateTimeImmutable()) (no future releases, closure resolved at validation time, does NOT round-trip to JSON Schema) + useTimezone('UTC')

  - Date: a DateTime fixed to Y-m-d (User.birthDate)

  - Time: a DateTime fixed to H:i:s (a track offset)

  - Map: nested object spread across FLAT columns; fields(...children); each child reads/writes its own column; top-level constraints limited to required()/nullable(); WORKED EXAMPLE — an address Map with one readOnly child; child violations surface as /data/attributes/address/<child> pointers; Map::on(relation) out of core scope

  - ArrayList: minItems/maxItems/uniqueItems/each(...constraints)/sorted(); WORKED EXAMPLE — Track.genres minItems(1)->each(Str rules)->uniqueItems()

  - ArrayHash: minProperties/maxProperties/sortKeys/sortValues; the Map-vs-ArrayHash contrast (declared keys vs dynamic keys)

  - Email/Url/Uuid/Slug/Ip subtypes: pure sugar; make() pre-attaches the format; the extra helper each adds (strict()/allowedSchemes()/version()/none/v4()-v6()-both()); the reconcile-not-stack note (strict() replaces, never doubles)

*Capabilities:* Str, Integer, Decimal, Boolean, DateTime, Date, Time, Map, ArrayList, ArrayHash, Email, Url, Uuid, Slug, Ip, format subtype equivalence + reconcile-not-stack


**`ids.md`** — Resource identifiers and client-generated ids  

*Role:* The Id field + the id lifecycle (server-generated vs client-generated). Audience: anyone needing a non-default id source/format or client-generated ids. Split from field-types.md because Id is special-cased into top-level id.  

*Outline (progressive disclosure):*

  - Id is usually implicit: a resource that declares no Id defaults to reading the id column/getId()

  - When to declare one: a non-id source column (Id::make('uuid')) or a client-generated-id format rule

  - Id renders to top-level id (NOT attributes) and is serialized as a string

  - Format helpers for a client-generated id: uuid(?version) / numeric() / pattern(regex)

  - Server-side generation: generateId() defaults to RFC-4122 v4 UUID; override for another scheme

  - Client-generated ids: acceptsClientGeneratedId() default false (spec lets the server reject); opt-in; validateClientGeneratedId path to ClientGeneratedIdNotSupported/Required/AlreadyExists

  - lid recap (forward reference, not resolved within a request) to concepts.md

  - The Id-attribute vs the Uuid-attribute-field distinction

*Capabilities:* Id field (uuid/numeric/pattern), Implicit id default, generateId() override, acceptsClientGeneratedId(), Client-generated id validation + exceptions


**`relations.md`** — Relationships and the relation DSL  

*Role:* Every relation type and the full AbstractRelation policy surface. Audience: anyone modelling relationships. The page that closes the biggest current-docs drift (MorphToMany + the whole policy surface).  

*Outline (progressive disclosure):*

  - Relations are fields producing the relationships member; related resource serializes through the registry; type()/types() declares allowed related type(s) and auto-adds the RelationshipType inbound constraint

  - BelongsTo (FK on owning model) — WORKED: album to artist; minimal declaration, storedAs('artist_id'), how the default apply stores the parsed linkage id; an empty to-one renders data:null

  - HasOne (FK on related model) — one-line: extends BelongsTo, identical to core, distinction is advisory for adapters; artist to featuredAlbum

  - HasMany — WORKED: album to tracks; minItems/maxItems producing 422s; applyToMany maintains a deduplicated id set under Mode Replace/Add/Remove

  - BelongsToMany (pivot-backed) — WORKED: track to playlists with pivot fields(Closure|array) position/addedAt; the plain statement that fields() is DECLARE-ONLY in core 1.0 (carried as metadata, consumed by the bundle Doctrine adapter); pivotFields() accessor

  - MorphTo (polymorphic to-one) — WORKED: favorite to favoritable via types('tracks','albums','artists'); serializer resolved at runtime from the related object own type; null to data:null via the first declared serializer

  - MorphToMany (polymorphic to-many) — WORKED: library to items mixed; one PolymorphicSerializer renders mixed members; a member matching no declared type throws; forward pointer to the bundle limitation

  - Backing & advisory metadata: storedAs()/computed(), inverseType() (advisory), cannotEagerLoad() (advisory)

  - Conventional links + withoutLinks(): self+related by convention; withUriFieldName() overrides the segment; links gated by endpoint exposure so a link never points at a 404

  - dataOnlyWhenLoaded(): load-aware linkage over the load-state seam; the three override rules (included-wins, withoutLinks-always-emits, no-load-state-injected = treated as loaded)

  - Endpoint exposure: withoutRelatedEndpoint()/withoutRelationshipEndpoint() (host 404 + omit the matching link)

  - Mutation gates: cannotReplace/cannotRemove/cannotAdd (to relationship-mutation.md)

  - Per-relation paginate() (to pagination.md; resolution relation then related resource then server default)

  - Custom relation hooks: extractUsing/fillUsing + the public readValue() accessor a data layer drives related/relationship endpoints with

*Capabilities:* BelongsTo, HasOne, HasMany (minItems/maxItems), BelongsToMany (+pivot fields/pivotFields), MorphTo (types), MorphToMany (types), type()/types()/relatedTypes(), storedAs/inverseType/cannotEagerLoad, Conventional links + withoutLinks + withUriFieldName, dataOnlyWhenLoaded, withoutRelatedEndpoint/withoutRelationshipEndpoint, per-relation paginate(), relation extractUsing/fillUsing/readValue


**`constraints.md`** — Validation constraints (vocabulary reference)  

*Role:* The constraint model + the full vocabulary as a SUMMARY TABLE (as mandated). Audience: anyone declaring validation. Teaches the metadata/Context model first, then the table, then the few nuance examples.  

*Outline (progressive disclosure):*

  - The defining behaviour FIRST: constraints are declarative METADATA core never executes — the structural subset compiles to JSON Schema (SchemaCompiler), the full set is translated by a framework adapter

  - The create/update Context model: Context(onCreate,onUpdate) / always()/onlyCreate()/onlyUpdate(); appliesTo(creating); per-field onCreate()/onUpdate() re-stamp; constrain() does NOT re-stamp

  - Required/Nullable semantics worked once: required on create = present+non-empty; on update absence = no change; nullable widens to explicit null (independent of presence)

  - THE CONSTRAINTS SUMMARY TABLE: name / applies-to (which field types + emitting method) / description / options — every VO: Required, Nullable; Min/Max/ExclusiveMin/ExclusiveMax/MultipleOf; MinLength/MaxLength/Pattern; EmailFormat/UrlFormat/UuidFormat/IpFormat/SlugFormat; MinItems/MaxItems/UniqueItems/MinProperties/MaxProperties; In/NotIn; Each; After/Before/Between; Sequentially/AtLeastOneOf; When; CompareField (+ the Comparison enum six cases); RelationshipType

  - WORKED — closure date bounds: a fixed bound is schema-visible (formatMinimum), a closure bound is adapter-only (does NOT round-trip)

  - WORKED — composition: an AtLeastOneOf of Sequentially groups (a valid URL OR a 10-char-min slug) and why a multi-rule alternative must nest in a Sequentially

  - WORKED — CompareField direction: this-field on the LEFT (endDate GreaterThan startDate)

  - WORKED — When fluent form: when(fn($v)=>..., fn($field)=>$field->minLength(10)->pattern(...)) (capture-buffer note; opaque PHP)

  - Boundary statement: core defines no executor and no entity-level seam (UniqueEntity-style checks live in the framework adapter)

  - RelationshipType is the one relation-facing constraint (not an attribute field)

  - constrain(): the typed custom-constraint escape hatch (build with onlyCreate/onlyUpdate to scope)

*Capabilities:* ConstraintInterface + Context model, Required/Nullable semantics, All numeric/string/array/object/format/enum/date/composition/conditional/cross-field/relationship constraints (table), Comparison enum, closure date bounds, constrain() escape hatch, the core boundary (no executor/entity seam)


### Querying


**`filters.md`** — Filtering collections  

*Role:* Declaring filters + the built-in catalogue + custom filters. Audience: anyone exposing filterable collections. Worked example FIRST, reference table after.  

*Outline (progressive disclosure):*

  - What a filter is: a metadata-only VO (a filter[<key>] key + target), execution lives in an adapter FilterHandlerInterface (the same metadata/handler split as constraints/sorts); declared via Resource::filters() (default none; library never auto-applies)

  - WORKED — a title search: Where::make('title')->operator('like') on tracks, plus ->asBoolean()->default(false) on an explicit flag (default round-trips as a real bool); show the request and the response

  - The FilterInterface contract (sole member key())

  - The built-in catalogue with exact make() signatures + defaults: Where; WhereIn/WhereNotIn; WhereIdIn/WhereIdNotIn; WhereNull/WhereNotNull; WhereHas/WhereDoesntHave — as a TABLE (key, target default, operator, capabilities)

  - Refinement helpers and which filters carry each: singular() (Where/WhereIn/WhereNotIn); delimiter() (the In family); deserializeUsing()/asBoolean() (Where); default() (all value-carrying)

  - WORKED — singular(): GET /artists?filter[slug]=radiohead returns ONE resource or null (zero-to-one collapse; no effect on relationship endpoints)

  - Defaults + presence-override: requested key wins by PRESENCE (array_key_exists); FilterDefaults::apply folds defaults once; presence-only filters deliberately don't carry a default

  - WORKED — relationship-existence: WhereHas('tracks') (albums that have tracks); core ships only metadata, the in-memory handler tests non-empty/non-null, a Doctrine adapter renders EXISTS (to adapters.md)

  - List/set values: array vs delimited string, split in the handler

  - Writing a custom filter: implement FilterInterface, list in filters(), add a handler arm; an unrecognised VO to UnsupportedFilter (500, server-config error) — WORKED: a within-radius geo-filter VO

  - How requested filters reach a handler: queryParameters()->filter / getFiltering()

*Capabilities:* FilterInterface, Where (operators, asBoolean, deserializeUsing), WhereIn/WhereNotIn, WhereIdIn/WhereIdNotIn, WhereNull/WhereNotNull, WhereHas/WhereDoesntHave, singular()/SupportsSingular, default()/HasDefaultValue/FilterDefaults, delimiter(), custom filter + UnsupportedFilter, reading filters off the operation


**`sorts.md`** — Sorting collections  

*Role:* Declaring sorts + the batch handler contract + custom/computed sorts. Audience: anyone exposing sortable collections. Fixes the stale per-sort handler signature.  

*Outline (progressive disclosure):*

  - Sorts are metadata-only (key + column); ordering lives in a SortHandlerInterface (same split as filters)

  - The simplest case: mark a field ->sortable() and a SortByField is auto-derived — GET /tracks?sort=title,-trackNumber for free

  - AbstractResource::allSorts(): one SortByField per ->sortable() field, merged with explicit sorts() (later wins)

  - SortByField (the one built-in): make(key, ?column); column defaults to key; declare it explicitly only for a key not equal to field-name or a column override

  - SortInterface contract (key() WITHOUT the leading dash); direction is parsed off the request, not part of the key

  - Computed/multi-column sorts: any custom SortInterface from sorts() — WORKED: a computed trackCount sort on artists

  - The CORRECTED handler contract: SortHandlerInterface::apply(list<SortDirective> $sorts, mixed $query) — the FULL ordered list in ONE call (sort is non-commutative; the first field must stay primary everywhere)

  - SortDirective (public readonly: SortInterface $sort + bool $descending) — the unit the handler consumes

  - ArraySortHandler reference: one usort with a cascading comparator (handles SortByField only; a computed sort needs your arm) — the canonical adapter example (to adapters.md)

  - UnsupportedSort (500, server-config error)

  - Reading requested sorts: queryParameters()->sort / getSorting()

*Capabilities:* SortInterface, ->sortable() auto-derivation, allSorts() merge, SortByField, SortDirective, SortHandlerInterface (batch contract), ArraySortHandler, custom/computed sorts, UnsupportedSort, reading sorts off the operation


**`pagination.md`** — Paginating collections  

*Role:* The two-method paginator model + the four strategies + the push-down window. Audience: anyone paginating. Fixes the missing window()/WindowInterface and profile() omissions.  

*Outline (progressive disclosure):*

  - Pagination = two pieces: a strategy reading page[...] to a Page VO holding items + link/meta emission; pagination state lives on the Page, never on a collection

  - Declaring it: AbstractResource::pagination() returning PagePaginator::make() — the simplest wiring shown first

  - The two-method contract (the design rationale): window(request) is called FIRST by the data layer to fetch exactly the slice (push-down to LIMIT/OFFSET or array_slice); paginate(request, items, total) wraps the pre-windowed items + the separately-computed total — WORKED data-layer loop: window().offset/limit to query to count(*) to paginate()

  - WindowInterface / OffsetWindow(offset, limit): the push-down vocabulary; garbage page[...] to an empty window, not an error

  - PagePaginator (the baseline worked example): page[number]/page[size], with-er keys/defaults, immutable

  - OffsetPaginator (page[offset]/page[limit]) — table row vs the baseline

  - FixedPagePaginator (page[number] only; server-fixed size never echoed) — one-line contrast

  - CursorPaginator (its OWN worked example): does NOT implement PaginatorInterface, has no window(), no total to no last link; caller owns cursor extraction (cursorBefore/After + hasNext/hasPrevious); activates the cursor profile

  - The Page VO: linkSet() (absolute, query-string-preserving so filter/sort/fieldsets survive across pages), pageMeta(), AND profile() (the third method, previously omitted)

  - REFERENCE TABLE (after the examples): the four emitted page shapes side-by-side — which links, which meta.page keys; the two defensive behaviours (total/size <= 0 suppresses the whole link set; self/prev/next null at boundaries); cursor omits last by design

  - Per-relation paginate() (relation then related resource then server default) and Server::withDefaultPaginator() — the full fallback chain in one place

  - Rendering: DataResponse::fromPage() and RelatedResponse::fromPage() (related links scoped to the related URL); profile dropped unless server-registered

*Capabilities:* PaginatorInterface (window + paginate), WindowInterface/OffsetWindow, PagePaginator, OffsetPaginator, FixedPagePaginator, CursorPaginator, PageInterface (linkSet/pageMeta/profile), the four Page VOs, per-resource/per-relation/server-default resolution, fromPage()/RelatedResponse::fromPage(), CursorPaginationProfile activation


**`sparse-fieldsets-and-includes.md`** — Sparse fieldsets and compound documents (include)  

*Role:* fields[TYPE] and include — the two query params that shape which members and which related resources appear. Audience: anyone tuning payloads. Closes the Phase-1-deferred include coverage.  

*Outline (progressive disclosure):*

  - Sparse fieldsets: fields[type]=title,artist narrows which attribute/relationship members render; the engine applies it by invoking only surviving member callables; notSparseField() exempts a member; Id always present

  - WORKED — GET /albums?fields[albums]=title,artist on the music catalog

  - Includes: ?include=artist,tracks.playlists builds a compound document with included + primary-takes-precedence dedup; default-included relationships (getDefaultIncludedRelationships) apply when no ?include is sent

  - WORKED — GET /albums/1?include=tracks,artist showing data + included

  - How a relation participates: linkage always vs dataOnlyWhenLoaded (included-wins) — cross-link to relations.md

  - Reading the parsed query: queryParameters()->fields / ->includes

  - Spec errors: InclusionUnrecognized/InclusionUnsupported (to errors-and-exceptions.md)

*Capabilities:* Sparse fieldsets (fields[TYPE]), notSparseField participation, include + compound documents + dedup, default-included relationships, reading fields/includes off the operation


### Serialization & hydration control


**`serializers.md`** — Custom serializers and the polymorphic serializer  

*Role:* When and how to hand-write a SerializerInterface, and the read-side of polymorphism. Audience: a user a field walk can't model. Adds the previously-absent PolymorphicSerializer.  

*Outline (progressive disclosure):*

  - The escape-hatch tiers, cheapest first: a single field to serializeUsing()/extractUsing(); a whole type to a custom serializer (reach for it LAST)

  - The three when-to-use triggers: request-aware/conditional attributes, computed/derived values over multiple members or external data, multiple representations of one model

  - The 7-method SerializerInterface contract with exact signatures (getType/getId/getMeta/getLinks/getAttributes/getDefaultIncludedRelationships/getRelationships)

  - The statelessness guarantee (pure functions; one instance serializes many objects incl. recursive includes) and the maps-of-callables design (return a closure per attribute, invoked only if it survives sparse-fieldset filtering)

  - WORKED — a TrackSerializer: a request-aware nowPlaying gated on the authenticated user, a displayTitle computed from several columns

  - AbstractSerializer = SerializerInterface + TransformerTrait (toDecimal/toIso8601Date/DateTime/fromSql); the trait is independently composable

  - The override constraint: override serializers are instantiated with new (no constructor args, no SerializerResolverInterface injected) — best for attribute-shaping, not related-resource serialization

  - Registration: Server::register(Resource::class, serializer:) (override wins for read, Resource still hydrates); bare path via registerSerializerHydrator() (to capability-composition.md)

  - UriTypeAwareInterface: a standalone serializer can declare its URL segment

  - SerializerResolverAwareInterface: a standalone serializer renders relationships only if it accepts the injected resolver

  - PolymorphicSerializer (the read-side of polymorphism): a decorator resolving each member real serializer (typically via RelationInterface::resolveSerializer) and delegating — WORKED with favorite to favoritable and library to items

*Capabilities:* SerializerInterface (7 methods + statelessness), AbstractSerializer + TransformerTrait, field-hook alternative, override constraint (no ctor args), UriTypeAwareInterface, SerializerResolverAwareInterface, PolymorphicSerializer, RelationInterface::resolveSerializer


**`hydrators.md`** — Custom hydrators and relationship-write hydration  

*Role:* When and how to hand-write a HydratorInterface, the three operation-scoped bases, and the relationship-mutation hydration seam. Audience: a user a field walk can't model on write. Adds the missing UpdateRelationshipHydratorInterface + the narrower bases.  

*Outline (progressive disclosure):*

  - The escape-hatch tiers: a single field to deserializeUsing()/fillUsing(); a whole type to a custom hydrator (LAST)

  - The three when-to-use triggers: split one member across columns / merge several, derive related models during a write, multi-step/transactional writes

  - The single-method HydratorInterface::hydrate(request, $domainObject) contract (fresh on create, fetched on update; returns the possibly-replaced object; throw typed exceptions directly)

  - The three operation-scoped bases TABLE: AbstractHydrator (create+update+relationship), AbstractCreateHydrator (create-only), AbstractUpdateHydrator (update+relationship)

  - The hook set: getAcceptedTypes/getAttributeHydrator/getRelationshipHydrator/setId/generateId/validateClientGeneratedId/validateRequest + the post-hydration validateDomainObject() seam (where adapter-level entity validation hangs)

  - Attribute callable ($obj,$value,$data,$name) and relationship callable signatures; mutate-in-place-or-return; PATCH absent = no change

  - Cardinality by type-hint: hint the second param ToOneRelationship | ToManyRelationship to RelationshipTypeInappropriate on mismatch; the ToOne/ToMany VOs (isEmpty() = clear, the id/type/lid accessors)

  - WORKED — a PlaylistHydrator: a single title member populating both slug and a normalised title; relationship by lid

  - UpdateRelationshipHydratorInterface: hydrateRelationship(rel, request, $obj) for the standalone /relationships/{rel} write endpoints (verb to Mode); AbstractResource implements it (cardinality + mutability flags enforced)

  - The direct hydrate() route for transactional writes

  - Registration: register(hydrator:) override / bare registerSerializerHydrator() (to capability-composition.md)

*Capabilities:* HydratorInterface, AbstractHydrator/AbstractCreateHydrator/AbstractUpdateHydrator, hook set incl. validateDomainObject, attribute/relationship callables, cardinality by type-hint, ToOneRelationship/ToManyRelationship, UpdateRelationshipHydratorInterface, HydratorResolverInterface, lid on create


**`capability-composition.md`** — Composing a type from independent capabilities  

*Role:* The capability-composition thesis: a type = serializer + hydrator + relations (+ provider + persister) in any combination, none coupled to AbstractResource. Audience: a user beyond the AbstractResource on-ramp. Owns override-resolution + standalone registration so resources.md stays simple.  

*Outline (progressive disclosure):*

  - The thesis: AbstractResource is pure sugar bundling serializer+hydrator+relations+uri-segment; you can keep it and override one concern, or skip it and register bare capabilities

  - register(class-string, serializer:, hydrator:) — override resolution: a custom serializer/hydrator wins ahead of the Resource fallback per concern — WORKED

  - registerSerializerHydrator(type, serializer:, hydrator:) — a bare pair under an explicit type with NO Resource (one or more required); resourceFor() throws NoResourceRegistered for a bare pair (the boundary proving decoupling)

  - Read-only vs write-only types: a read endpoint needs only a serializer; a write-only ingest needs only a hydrator

  - The resolver mirror: SerializerResolverInterface (read) vs HydratorResolverInterface (write), both backed by the Server registry; read/write symmetry made concrete

  - WORKED — a standalone read-only chart type registered as a bare serializer (no Resource, no hydrator)

*Capabilities:* register() override params, registerSerializerHydrator() standalone, override resolution order, SerializerResolverInterface/HydratorResolverInterface, read-only / write-only types, NoResourceRegistered boundary


### Request/response lifecycle


**`server.md`** — The Server: configuring an API  

*Role:* The Server configuration root + lazy instantiation + container resolution + multi-versioning. Audience: anyone wiring an API. The lifecycle section anchor.  

*Outline (progressive disclosure):*

  - Server is an immutable single-version config root: make() empty; every with-er/register returns a NEW instance cloning underlying registries

  - The full configurator surface: withBaseUri/withVersion/withDefaultMeta/withEncodeOptions/withDefaultPaginator/withPsr17/register/registerSerializerHydrator/withContainer/withRelationshipLoadState/withProfile/withMiddleware/withHandler

  - Matching accessors: baseUri/jsonApiVersion/defaultMeta/encodeOptions/defaultPaginator/profiles/responseFactory/streamFactory(throw if unset)/serializerFor/hydratorFor/hasSerializerFor/hasHydratorFor/resourceFor/relationshipLoadState

  - Lazy instantiation: register() reads static $type WITHOUT instantiating; instances built lazily on first lookup (so a Resource with constructor deps works)

  - withContainer: a PSR-11 container OR any callable(class-string):object (normalised to one closure); order-independent vs register()

  - withRelationshipLoadState: inject the storage-aware load-state predicate relations consult for dataOnlyWhenLoaded (null = treat every relation as loaded)

  - Wiring errors are LogicException, not error documents (duplicate type, duplicate profile URI, wrong-type factory return, missing PSR-17, missing handler)

  - Two dispatch paths: handle() (full PSR-15 chain) vs dispatch(operation) (calls the OperationHandlerInterface directly, returns the UNRENDERED VO; requires an OperationHandlerInterface inner handler) — WORKED contrast

  - Multi-server/versioning: one Server per version, selection is routing OUTSIDE core (a prefix-dispatcher sketch)

*Capabilities:* Server immutability + with-ers/accessors, lazy instantiation, withContainer (PSR-11/callable), withRelationshipLoadState, register/registerSerializerHydrator (cross-ref), handle() vs dispatch(), LogicException wiring faults, multi-server/versioning


**`operations.md`** — Operations and dispatch  

*Role:* The operation model: the nine VOs, Target, OperationFactory, the handler, the PSR-7 adapter, OperationContext, QueryParameters. Audience: anyone writing a handler or a custom integration.  

*Outline (progressive disclosure):*

  - The common contract: JsonApiOperationInterface (target/queryParameters/context); the five mutating ops add body()

  - The nine operation VOs and their endpoints TABLE: FetchResource (collection or single via target()->hasId()), FetchRelated, FetchRelationship, CreateResource, UpdateResource, DeleteResource, UpdateRelationship, AddToRelationship, RemoveFromRelationship

  - WORKED — a handler written as match(true) over concrete operation types (the music-catalog handler)

  - Target: the router-agnostic endpoint identifier (type/id/relationship/isRelationshipEndpoint); hasId()/hasRelationship(); the four shapes; attached as a request attribute keyed by Target::class

  - OperationFactory::fromRequest(request, target, context): the single method-by-target-shape dispatch table; a stateless INSTANCE method (construct, then call); unhandled method to ApplicationError (500); the override seam for custom integrations

  - OperationContext: the ResolvingServerInterface + optional originating PSR-7 request; httpRequest() returns null for a programmatic dispatch — WORKED contrast (the same handler serving HTTP and dispatch())

  - QueryParameters: the parsed fields/includes/sort/filter/page projection; fromRequest() is just the HTTP-side constructor (a programmatic caller builds it directly)

  - OperationHandlerInterface: the ONE seam to implement (or decorate); the closed return union of six response VOs; auto-adapted to PSR-15 by Psr7ToOperationHandlerAdapter

  - Psr7ToOperationHandlerAdapter: the PSR-15-to-operations join; reads Target, wraps the request, builds context, encodes the returned VO; missing Target to 500 ErrorResponse (not an exception); the Symfony bundle bypasses it

*Capabilities:* JsonApiOperationInterface, the nine operation VOs, Target, OperationFactory, OperationContext (httpRequest null path), QueryParameters, OperationHandlerInterface, Psr7ToOperationHandlerAdapter, ResolvingServerInterface


**`related-endpoints.md`** — Related and relationship read endpoints  

*Role:* GET /{type}/{id}/{rel} and GET .../relationships/{rel} (linkage). Audience: anyone exposing relationship reads, incl. polymorphic + paginated related collections. Pulls the related-read behaviour into its own page.  

*Outline (progressive disclosure):*

  - Two endpoints per relation: the related read (full related resource(s)) vs the relationship/linkage read (identifiers only)

  - WORKED — GET /albums/1/artist (RelatedResponse single) and GET /albums/1/relationships/tracks (IdentifierResponse linkage)

  - RelatedResponse: fromResource/fromCollection/fromPage (links scoped to the related URL); an empty to-one renders data:null

  - IdentifierResponse: forRelationship(parent, parentSerializer, relName) — linkage only

  - Paginated related collections: per-relation paginate() resolution (to pagination.md); GET /albums/1/tracks paginated

  - Polymorphic related endpoints: MorphTo/MorphToMany reuse the SAME FetchRelated/FetchRelationship operations — polymorphism resolved in the serializer; the to-one resolves its serializer from the related object, the to-many renders mixed members (forward pointer: the bundle Doctrine provider throws for a polymorphic to-many; in-memory supports it)

  - Endpoint exposure interplay: withoutRelatedEndpoint/withoutRelationshipEndpoint to 404 + the matching link omitted (to relations.md)

  - Compound includes on these endpoints (to sparse-fieldsets-and-includes.md)

*Capabilities:* FetchRelatedOperation, FetchRelationshipOperation, RelatedResponse (incl. fromPage), IdentifierResponse, per-relation pagination, polymorphic related rendering, endpoint exposure interplay, empty to-one data:null


**`relationship-mutation.md`** — Mutating relationships  

*Role:* PATCH/POST/DELETE .../relationships/{rel} — replace/add/remove. Audience: anyone writing relationship endpoints. Covers the mutation gates and their 403s.  

*Outline (progressive disclosure):*

  - The verb trio: PATCH=replace (UpdateRelationship), POST=add (AddToRelationship), DELETE=remove (RemoveFromRelationship); each maps to a Mode

  - WORKED — the replace/add/remove trio on track to playlists (request + 200/204 outcomes)

  - How linkage applies: id to stored object/managed reference; the same path reused for relationships embedded in a whole-resource write

  - Mutation gates: cannotReplace/cannotRemove/cannotAdd to FullReplacementProhibited/RemovalProhibited/AdditionProhibited (each a 403); for a to-one, clearing with data:null counts as a removal

  - Cardinality enforcement: a to-many body against a to-one relation to RelationshipTypeInappropriate; an unknown relation to RelationshipNotExists

  - Where the write lands: AbstractResource::hydrateRelationship (verb to Mode) or a custom hydrator hydrateRelationship (to hydrators.md)

  - Empty linkage = clear

*Capabilities:* UpdateRelationshipOperation/AddToRelationshipOperation/RemoveFromRelationshipOperation, Mode enum, cannotReplace/cannotRemove/cannotAdd to 403s, cardinality enforcement, clear semantics, relationships in whole-resource writes


**`responses.md`** — Response value objects  

*Role:* The six response VOs a handler returns + the shared withers. Audience: anyone writing a handler. Fixes the major drift (NoContentResponse, withStatus, RelatedResponse::fromPage).  

*Outline (progressive disclosure):*

  - You never build a document by hand: construct a response VO via a named constructor, optionally chain document-level withers, return it; the library renders a spec-compliant PSR-7 response

  - DataResponse: fromResource (single, fixed at construction — an iterable single resource is never auto-collected), fromCollection, fromPage; withStatus() overrides the default 200 (e.g. 201 on create)

  - MetaResponse: fromMeta (meta-only document)

  - RelatedResponse: fromResource/fromCollection/fromPage (to related-endpoints.md)

  - IdentifierResponse: forRelationship (linkage only)

  - NoContentResponse: create() — always 204, body+Content-Type omitted; withStatus() is a no-op, body-level withers do nothing; the common DELETE outcome

  - ErrorResponse: fromErrors(Error ...)/fromException(JsonApiExceptionInterface); status derived by ErrorDocument — WORKED: a 404+422 collapses to 400, a uniform bag of 422s stays 422

  - The shared AbstractResponse withers TABLE (each returns a new instance): withMeta/withLinks/withJsonApi/withHeader/withHeaders/withEncodeOptions/withStatus (incl. the NoContentResponse no-op)

  - Rendering outside the operations flow: toPsrResponse(server, request)

*Capabilities:* DataResponse (fromResource/Collection/Page), MetaResponse, RelatedResponse, IdentifierResponse, NoContentResponse, ErrorResponse + status derivation, AbstractResponse withers incl. withStatus, toPsrResponse


**`content-negotiation.md`** — Content negotiation and request validation  

*Role:* The media-type + request-document rules enforced before a handler runs. Audience: anyone needing to understand 415/406/400 behaviour. Makes the RequestValidator surface coherent across negotiation and body parsing.  

*Outline (progressive disclosure):*

  - The single media type application/vnd.api+json may carry only ext/profile params; enforced on request before the handler, emitted on response

  - RequestValidator (the public surface; MediaType is the @internal rule engine): negotiate() / validateQueryParams() / validateJsonBody() / validateTopLevelMembers()

  - Content-Type rule to 415 for any forbidden param; Accept rule to 406 only when EVERY application/vnd.api+json instance carries a forbidden param (a single conforming instance is acceptable, q ignored) — WORKED contrast of the asymmetry

  - Query-parameter validation to 400 (QueryParamUnrecognized) for an unrecognised JSON:API-family param; the recognised families (fields/include/sort/page/filter/profile)

  - Body structure validation (the body-parsing stage): validateJsonBody to 400; validateTopLevelMembers to data/errors mutual exclusion + required members + included-requires-data

  - Profiles flow through untouched at this layer (advisory) — only extensions can fail negotiation; the empty-by-default supported-extension set

  - The request profile/extension introspection read-side (getApplied/Requested/RequiredProfiles, getRequested/AppliedExtensions) — the inputs the response layer consumes

  - ResponseValidator: validateContentTypeHeader / validateJsonBody (defensive, dev/CI)

*Capabilities:* RequestValidator (negotiate/validateQueryParams/validateJsonBody/validateTopLevelMembers), 415 vs 406 asymmetry, query-param validation, top-level member rules, extension negotiation (empty default set), profile/extension introspection, ResponseValidator


**`errors-and-exceptions.md`** — Errors and the exception catalogue  

*Role:* How errors propagate + the full typed-exception catalogue as ONE reference table. Audience: anyone producing or debugging errors. Merges the old errors.md (propagation) + exceptions.md (catalogue); fixes the missing AdditionProhibited/FilterParamUnrecognized + the count.  

*Outline (progressive disclosure):*

  - The model: THROW a typed exception anywhere downstream; the outermost ErrorHandlerMiddleware catches it to ErrorResponse::fromException to a spec-compliant error document with status from the exception

  - The alternative: return ErrorResponse::fromErrors(Error ...) from a handler (build Error + ErrorSource by hand); throw-vs-return guidance

  - Building an Error: id/status/code/title/detail/source/links/meta (all optional); ErrorSource::fromPointer/fromParameter/fromHeader — the body/query/header triad TABLE

  - Unexpected throwables to a debug-gated generic 500 (InternalServerError::for: debug off = bare status+title; debug on = code+detail+meta{exception,file,line,trace} with call ARGUMENTS stripped); optional PSR-3 logging regardless of debug

  - JsonApiExceptionInterface (getErrors/getStatusCode) + AbstractJsonApiException; writing your own (worked PaymentRequired 402)

  - THE FULL CATALOGUE TABLE (regenerated from src/Exception/ — 37 concrete classes; do NOT hard-code a count in prose): grouped by category (body/document structure, resource identifier, client-generated id, relationship incl. AdditionProhibited, query parameters incl. FilterParamUnrecognized, content negotiation, resource/lifecycle, response-side) with exception to status to code to when-thrown to source kind; preserve the class/code asymmetries (SortParamUnrecognized to SORTING_UNRECOGNIZED) and the dynamically-resolved statuses

  - The 5xx-signals-server-fault callout (NoResourceRegistered, ApplicationError)

  - What the error handler does NOT do: it doesn't inspect a successful return; response VOs aren't ResponseInterface so they're rendered by the operations adapter

*Capabilities:* throw-typed-exception model, ErrorHandlerMiddleware, ErrorResponse fromException/fromErrors, Error + ErrorSource triad, InternalServerError::for (debug gating, args stripped), PSR-3 logging, JsonApiExceptionInterface + AbstractJsonApiException, the full 37-class catalogue incl. AdditionProhibited + FilterParamUnrecognized, writing your own exception


**`middleware.md`** — The middleware suite and ordering  

*Role:* The PSR-15 middleware suite + the single canonical ordering treatment. Audience: anyone assembling the chain. Merges the duplicated middleware.md + middleware-order.md.  

*Outline (progressive disclosure):*

  - The six middleware + exact constructor signatures: ErrorHandlerMiddleware(ServerInterface, debug=false, ?Logger); ContentNegotiationMiddleware(string ...$ext); RequestBodyParsingMiddleware(); RequestValidationMiddleware(ServerInterface, DocumentValidator); ResponseValidationMiddleware(ServerInterface, DocumentValidator, throwOnViolation=true, ?Logger); JsonApiMiddleware aggregate

  - Per-server model: each Server holds its own ordered list and IS a RequestHandlerInterface folding the list over the inner handler; no global registry

  - The single canonical order (outermost-first), optional middleware shown in place: 1 ErrorHandler then 2 ContentNegotiation then 3 RequestBodyParsing then 4 RequestValidation (optional dev/CI) then 5 Handler then 6 ResponseValidation (optional dev/CI); order is a recommendation, error-handler-outermost the only firm rule

  - Order rationale: negotiation before body-parsing; error handler must wrap to catch negotiation/parsing/handler throwables; ResponseValidation placement (just inside the error handler, runs last on the unwind)

  - The three core middleware suffice for a compliant endpoint; the two validation middleware are opt-in and need opis/json-schema (to schema-validation.md)

  - JsonApiMiddleware aggregate composes the three core in order behind one MiddlewareInterface (same debug/logger/ext args); the blocks stay independently constructable

  - The recommended handler = OperationHandlerInterface wrapped in Psr7ToOperationHandlerAdapter; any RequestHandlerInterface works

  - Parsed-request flow: the first middleware wraps the PSR-7 request in JsonApiRequest (idempotent), one memoized parse shared downstream, only Operation\Target::class read as a routing attribute

  - Profiles are advisory and flow through untouched; response VOs aren't ResponseInterface (render via the adapter or toPsrResponse)

  - Operational notes: PSR-17 factories come from the Server; max body SIZE is not enforced in core (to security.md)

*Capabilities:* the six middleware + signatures, per-server model, the canonical order + rationale, JsonApiMiddleware aggregate, wrap-once JsonApiRequest, the two validation middleware placement, advisory profile passthrough, operational notes (factories, body size)


### Cross-cutting


**`adapters.md`** — Writing a data-layer adapter (filters, sorts, constraints)  

*Role:* The metadata/execution split that keeps core storage-agnostic + the reference in-memory handlers as the worked adapter. Audience: anyone executing filters/sorts/constraints against a real store.  

*Outline (progressive disclosure):*

  - The core principle: core ships typed metadata VOs (constraints/filters/sorts) describing intent but NEVER executes them; execution lives in adapter handlers + translators

  - No generic Query interface by design: a handler query parameter is mixed (Doctrine narrows to QueryBuilder, in-memory to array)

  - The metadata contracts: ConstraintInterface::context(), FilterInterface::key(), SortInterface::key()

  - FilterHandlerInterface<TQuery>::apply(filter, query, value) and SortHandlerInterface<TQuery>::apply(list<SortDirective>, query) — both throw UnsupportedFilter/UnsupportedSort (500, server-config) on an unrecognised VO

  - WORKED — the reference ArrayFilterHandler: the exact operator semantics (like = case-insensitive ASCII substring, the LIKE reference; === strict; etc.), toList() set splitting, hasRelation() existence test, Accessor::get reading arrays-or-objects

  - WORKED — the reference ArraySortHandler: one usort with a cascading comparator honouring the single-call ordered-list contract

  - The fold-over-query example: iterating $resource->filters() against queryParameters()->filter by key, $resource->allSorts() against ->sort

  - Extending the vocabulary: a custom FilterInterface/SortInterface VO + a handler arm written together

  - Constraints follow the same split: the JSON Schema compiler is the one BUILT-IN core consumer (structural subset); a framework adapter translates the FULL set to native validator rules; custom constraints attach via constrain() and are matched by CLASS (the translator contract is bundle-side, NOT a core interface)

  - ORM-backed adapters belong outside core (the Symfony bundle); core ships only the in-memory reference

*Capabilities:* metadata/execution split, no-generic-Query decision, FilterHandlerInterface, SortHandlerInterface, ArrayFilterHandler (reference semantics), ArraySortHandler (reference), fold-over-query, extending the vocabulary, constraint translation boundary, UnsupportedFilter/UnsupportedSort


**`schema-validation.md`** — Optional JSON-Schema validation (opis)  

*Role:* The opt-in opis-backed document validation pipeline. Audience: a user who wants structural conformance in dev/CI. Positioned as a dev/CI aid, not a runtime firewall.  

*Outline (progressive disclosure):*

  - Framing: this is an opt-in dev/CI conformance aid, NOT a runtime firewall (to security.md); needs opis/json-schema (suggest, never require)

  - DocumentValidator(SchemaProviderInterface): validateRequest to RequestBodyInvalidJsonApi (400, id may be omitted, lid allowed); validateResponse to ResponseBodyInvalidJsonApi (500, type+id required); max 20 errors so several surface; each opis leaf to a violation with its JSON Pointer (source.pointer)

  - SchemaProviderInterface / VendoredSchemaProvider: the request vs response roots; the vendored JSON:API 1.1 schemas; the unevaluatedProperties relocation

  - WORKED — the allOf composite + unevaluatedProperties relocation: how a profile fragment can ADD a member the base schema would reject

  - SchemaCompiler::compile(resource, creating): a per-type fragment tightening create (Required to required[]) vs update (absent allowed; only requiredOnUpdate + supplied values constrained); When and CompareField are SKIPPED (don't round-trip); closure date bounds skipped

  - SchemaContributingProfileInterface::schemaFragment(): the profiles-to-validation bridge

  - Wiring: RequestValidationMiddleware/ResponseValidationMiddleware (to middleware.md)

*Capabilities:* DocumentValidator (request/response), SchemaProviderInterface/VendoredSchemaProvider, SchemaCompiler (create vs update; skipped constraints), SchemaContributingProfileInterface, allOf composite + unevaluatedProperties, the validation middleware wiring


**`profiles.md`** — JSON:API profiles  

*Role:* Implementing/registering JSON:API 1.1 profiles. Audience: anyone adding profile semantics. Owns the profile CONTRACT; defers render-time surfacing mechanics to content-negotiation/responses.  

*Outline (progressive disclosure):*

  - Profiles are advisory: a server applies recognised profiles and silently ignores unknown ones (the defining contrast with extensions)

  - ProfileInterface: uri() (matched against the negotiated profile param, advertised in links.profile + the Content-Type profile param); keywords() (reserved names; introspection only, does NOT gate negotiation); finalizeDocument(document, request) (run once per applied profile after body assembly, before encode)

  - AbstractProfile convenience base (keywords()=[], finalizeDocument()=identity)

  - WORKED — a custom profile stamping meta (a generated-at TimestampProfile)

  - The bundled CursorPaginationProfile as the real worked example: the Resnick URI, reserved page[size]/[after]/[before], auto-activated by a CursorBasedPage (to pagination.md)

  - Registration: Server::withProfile() (new immutable instance); the URI-keyed ProfileRegistry (register/has/get/all); re-registering a URI to ProfileAlreadyRegistered (a LogicException, NOT a JsonApiExceptionInterface — a wiring bug)

  - How applied profiles are surfaced at render time (brief; canonical detail in content-negotiation.md/responses.md): intersect requested/required with registered, run finalizeDocument, record links.profile + Content-Type param + Vary: Accept

  - SchemaContributingProfileInterface bridge (to schema-validation.md)

*Capabilities:* ProfileInterface, AbstractProfile, finalizeDocument hook, keywords(), ProfileRegistry, ProfileAlreadyRegistered, Server::withProfile, CursorPaginationProfile, render-time surfacing (brief), SchemaContributingProfileInterface (cross-ref)


**`links-and-meta.md`** — Links and meta  

*Role:* Setting document/resource/relationship links and meta. Audience: anyone enriching documents beyond the field DSL. Pulls the links/meta detail out of concepts.md into an actionable how-to.  

*Outline (progressive disclosure):*

  - meta everywhere: free-form array<string,mixed> at document/resource/relationship/link/error/jsonapi levels; empty = omit

  - Document-level: withMeta() / withLinks(DocumentLinks) / withJsonApi(JsonApiObject) on any response

  - Resource-level: getMeta()/getLinks() on a serializer (or AbstractResource overrides)

  - Link forms: bare-string vs link-object ({href, meta, rel, describedby, title, type, hreflang}); link containers (DocumentLinks/ResourceLinks/RelationshipLinks/ErrorLinks) with a baseUri prepend; reserved relations + custom

  - Conventional relationship links (self/related) are auto-emitted (to relations.md); pagination links auto-emitted by fromPage() (to pagination.md)

  - Server-level defaults: withDefaultMeta() / withVersion() feeding the jsonapi object

*Capabilities:* meta at all levels, withMeta/withLinks/withJsonApi, getMeta/getLinks on a serializer, link forms + containers + baseUri prepend, default meta / jsonapi version


**`security.md`** — Security posture and deployment obligations  

*Role:* What the library guarantees against malicious input and what the consumer must own. Audience: anyone deploying. Describes behaviour without leaning on the @internal MediaType class.  

*Outline (progressive disclosure):*

  - Scope: a server-side request/response engine — NOT auth/authz, rate limiting, or transport security

  - Guarantee — bounded body parsing: json_decode with depth 512 + JSON_THROW_ON_ERROR everywhere to 400, never a silent null; the depth caps NESTING not SIZE (hence the body-size duty)

  - Guarantee — safe header parsing: a linear non-backtracking scanner with disjoint classes (described as behaviour, not by the internal class name) to no catastrophic backtracking; malformed headers to 415/406

  - Guarantee — debug-gated errors: production-safe by default; debug on strips stack-frame arguments so secrets in args never serialise (to errors-and-exceptions.md)

  - Guarantee — allow-list write hydration: hydration walks the DECLARED field inventory, never the client keys; undeclared members (the isAdmin over-post) silently ignored; readOnly* skipped; client id rejected unless opted in — a STRUCTURAL guard, NOT per-user field authorisation

  - Reflected-input caveat: a few errors echo the requester own Accept/Content-Type/body back to that same requester — reflection, not a server-state leak

  - Consumer duties: a body-SIZE cap upstream; debug OFF in prod; authenticate/authorise OUTSIDE the JSON:API chain; treat hydrated input as untrusted; the schema-validation middleware is a dev/CI aid, not a firewall

  - Vulnerability reporting

*Capabilities:* scope statement, bounded body parsing, safe header parsing, debug-gated errors + args stripping, allow-list hydration / no mass-assignment, reflected-input caveat, consumer duties, reporting


**`testing.md`** — Testing helpers  

*Role:* The runtime Testing\ utilities for asserting over JSON:API documents and driving handle()/dispatch(). Audience: anyone testing a consumer app.  

*Outline (progressive disclosure):*

  - The utilities live in the RUNTIME autoload (no extra wiring); deliberately minimal — no factories/fixtures/DB traits/HTTP clients

  - The four accepted input shapes (PSR-7 response / raw JSON / parsed array / a response VO + ServerInterface)

  - JsonApiDocument: assertHasType/Id/Attribute/Relationship/Included/NotIncluded/MetaKey/MetaValue/Link/ProfileApplied (chainable); raw accessors data()/included()/meta()/links()/toArray() — WORKED against a rendered Album document

  - JsonApiErrors: assertCount/assertHasError(status?,pointer?,code?)/assertHasErrorAt/assertHasErrorWithCode/errors()

  - JsonApiRequestBuilder: get/post/patch/delete(uri, ServerRequestFactory, StreamFactory); withResource/withQueryParam/withProfile/withHeader; build() — and the reminder a real handle() test still needs a Target attached

  - JsonApiOperationBuilder: create/update/fetch/delete(..., ResolvingServerInterface); withAttribute/withRelationship/withRelationships; build() for the dispatch() path (pass the server to JsonApiDocument::of() to render the unrendered VO)

  - SpecCompliance::assert / AssertsSpecCompliance trait — one-line 1.1 conformance (needs opis/json-schema; defaults to VendoredSchemaProvider) (to schema-validation.md, spec-compliance.md)

*Capabilities:* JsonApiDocument, JsonApiErrors, JsonApiRequestBuilder, JsonApiOperationBuilder, SpecCompliance/AssertsSpecCompliance, the four input shapes


**`spec-compliance.md`** — JSON:API 1.1 compliance ledger  

*Role:* The per-spec-section compliance reference, code-and-test cited. Audience: an evaluator/auditor verifying conformance. Kept as a reference ledger even as the prose docs go example-first.  

*Outline (progressive disclosure):*

  - Scope: tracks JSON:API 1.1 FORMAT compliance ONLY — explicitly NOT OpenAPI

  - The status-legend discipline (tested / code-only / todo / intentionally-n-a-with-rationale) and the spec:<section> PHPUnit-group anchoring

  - Per-section matrix rows (each MUST/SHOULD to implementing class + covering test): document structure; errors (typed-exception-to-status map, regenerated count — do NOT carry a stale 33/37); fetching; inclusion; sparse fieldsets; sorting; pagination; filtering; CRUD (resources + relationships, client-generated ids, lid scope boundary); content negotiation (415/406, empty ext set); extensions & profiles (advisory ignore, applied-profile advertisement)

  - The opt-in structural validation story as a compliance aid (DocumentValidator + SchemaCompiler) (to schema-validation.md)

  - Internal-class evidence (transformer, QueryParam) cited as proof but excluded from user-facing capability claims

*Capabilities:* the full 1.1 compliance matrix, status legend + spec-group anchoring, scope boundary (not OpenAPI), typed-exception-to-status map (regenerated count), validation-as-compliance-aid


## Example-app spec — `examples/music-catalog/`

A music-catalog JSON:API served entirely from an in-memory data layer so every docs snippet is extracted from a CI-run test (the discipline the current getting-started page enforces, applied to the whole doc set). The app lives under examples/music-catalog/ in the core repo with its own composer test entry; it depends only on the library + a PSR-7/PSR-17 impl (nyholm/psr7). It is the single source of truth for every page: each capability page links to a real file here and quotes short snippets from it. Resources cover the full relation topology (BelongsTo, HasOne, HasMany, BelongsToMany+pivot, MorphTo, MorphToMany) and the full field-type set so each per-field-type section has a live referent. One standalone bare serializer (a read-only chart) witnesses the capability-composition thesis with no Resource.

### Resources

- **artists** — fields: Id id, Str name (required, sortable), Slug slug (sortable; singular filter), Url website (nullable), Str bio (nullable, maxLength), Integer trackCount (computed, backs the custom sort), DateTime createdAt (readOnlyOnUpdate, sortable)
  - relations: HasOne featuredAlbum to albums, HasMany albums to albums (dataOnlyWhenLoaded)
- **albums** — fields: Id id, Str title (required, maxLength, sortable), Decimal averageRating (readOnly, nullable), DateTime releasedAt (before(fn()=>new DateTimeImmutable()), useTimezone('UTC'), sortable), Map releaseInfo (Str label + Str catalogueNumber children, one readOnly child), Boolean explicit
  - relations: BelongsTo artist to artists (storedAs artist_id; WhereHas filter), HasMany tracks to tracks (paginate(PagePaginator), dataOnlyWhenLoaded)
- **tracks** — fields: Id id, Str title (required, sortable; Where like filter), Integer trackNumber (min(1), sortable), Integer durationSeconds (storedAs length_seconds), Boolean explicit (asBoolean filter, default false), ArrayList genres (minItems(1)->each(Str)->uniqueItems(); WhereIn filter), Time previewOffset (nullable), Str displayTitle (computed via extractUsing)
  - relations: BelongsTo album to albums, BelongsToMany playlists to playlists (pivot fields position/addedAt; cannotReplace)
- **playlists** — fields: Id id (uuid, client-generated; acceptsClientGeneratedId override), Str title (required; feeds a custom PlaylistHydrator that also fills the slug), Slug slug (readOnly), Boolean public, Uuid externalId (nullable)
  - relations: BelongsTo owner to users, BelongsToMany tracks to tracks (pivot fields position/addedAt; paginate)
- **users** — fields: Id id, Email email (required; strict on a variant), Str displayName (required), Date birthDate (nullable), ArrayHash preferences (minProperties/maxProperties, sortKeys), Ip lastSeenIp (nullable), Str password (hidden write-only) + Str passwordConfirm (CompareField/AtLeastOneOf/When demos)
  - relations: HasMany playlists to playlists, HasOne library to libraries
- **favorites** — fields: Id id, DateTime favoritedAt (readOnlyOnUpdate)
  - relations: BelongsTo user to users, MorphTo favoritable to tracks|albums|artists (types(); PolymorphicSerializer on the to-one)
- **libraries** — fields: Id id
  - relations: BelongsTo owner to users, MorphToMany items to tracks|albums|artists (types(); PolymorphicSerializer; in-memory mixed-collection read)
- **charts** — fields: standalone bare serializer — NO Resource, NO hydrator: type/id/attributes {name, period, entries} via a hand-written ChartSerializer registered with registerSerializerHydrator; read-only, operation allow-list = fetch only

### Data layer

A single InMemoryStore (associative arrays keyed by type then id of plain domain objects) shared by the read path and write path so a create is immediately readable. An InMemoryRepository exposes fetchOne/fetchCollection/fetchRelatedCollection/create/update/delete plus the window-slice-count-paginate loop. A CriteriaApplier composes the library reference ArrayFilterHandler + ArraySortHandler (and a custom WithinRadius filter arm) to apply queryParameters()->filter/->sort before windowing. One MusicCatalogHandler implements OperationHandlerInterface with a match(true) over all nine operation VOs: FetchResource (collection via window/paginate, single via fetchOne then 404 ResourceNotFound), FetchRelated/FetchRelationship (driving readValue + RelatedResponse/IdentifierResponse, incl. the polymorphic and paginated related cases), CreateResource (hydratorFor->hydrate on a fresh object then 201 + Location via withStatus), UpdateResource (fetch-then-hydrate then 200), DeleteResource (then NoContentResponse 204), and the three relationship-mutation ops (hydrateRelationship by verb to Mode, honouring cannotReplace/Add/Remove then 403s). A PathPrefixRouter middleware attaches Operation\Target. bootstrap.php assembles the Server (withPsr17, register all seven Resources, registerSerializerHydrator the chart, withProfile a TimestampProfile, withDefaultPaginator, the recommended middleware list). A custom PlaylistHydrator and a TrackSerializer demonstrate the full-capability escape hatches; PaymentRequired demonstrates a custom exception.

### Must demonstrate

- AbstractResource + uriType + overridable methods (resources.md)
- Every field type individually: Str/Integer/Decimal/Boolean/DateTime/Date/Time/Map/ArrayList/ArrayHash/Id/Email/Slug/Ip/Url/Uuid (fields.md, field-types.md, ids.md)
- The four field hooks + computed/storedAs/hidden/readOnly scoping (fields.md)
- All six relation types + the full AbstractRelation policy surface: withoutLinks/withUriFieldName, dataOnlyWhenLoaded, withoutRelated/RelationshipEndpoint, cannotReplace/Remove/Add, paginate, inverseType/cannotEagerLoad, extractUsing/fillUsing/readValue (relations.md, related-endpoints.md, relationship-mutation.md)
- The full constraint vocabulary incl. closure date bounds, Sequentially/AtLeastOneOf, When, CompareField+Comparison, RelationshipType, constrain() (constraints.md)
- All nine built-in filters + singular()/default()/delimiter()/asBoolean()/deserializeUsing() + a custom FilterInterface VO (filters.md, adapters.md)
- ->sortable() auto-derivation, a computed trackCount sort, SortDirective + the batch SortHandler contract, ArraySortHandler (sorts.md, adapters.md)
- PagePaginator (resource + per-relation), the window-paginate push-down loop, OffsetWindow, and a CursorPaginator variant; per-relation/server-default resolution (pagination.md)
- Sparse fieldsets + include (compound documents, default-included relationships, dedup) (sparse-fieldsets-and-includes.md)
- A custom TrackSerializer (request-aware nowPlaying), AbstractSerializer/TransformerTrait, and the PolymorphicSerializer on favorites/library (serializers.md)
- A custom PlaylistHydrator (title to slug+title, validateDomainObject), the operation-scoped bases, cardinality-by-type-hint, UpdateRelationshipHydratorInterface (hydrators.md)
- register() override + registerSerializerHydrator() standalone chart (read-only type) + read/write resolver mirror (capability-composition.md)
- Server assembly, withContainer, withRelationshipLoadState, handle() vs dispatch() (server.md)
- The nine operations, Target shapes, OperationContext httpRequest-null, the handler, the PSR-7 adapter (operations.md)
- Every response VO incl. NoContentResponse + withStatus + ErrorResponse status derivation (responses.md)
- 415/406 negotiation, query-param validation, extension empty-set behaviour (content-negotiation.md)
- The throw model, a custom PaymentRequired exception, the debug-gated 500, the full catalogue exercised by error tests (errors-and-exceptions.md)
- The recommended middleware list incl. the optional validation middleware (middleware.md, schema-validation.md)
- ArrayFilterHandler/ArraySortHandler as the worked reference adapter + custom vocabulary (adapters.md)
- A TimestampProfile + the CursorPaginationProfile activation (profiles.md)
- Document/resource/link/meta enrichment (links-and-meta.md)
- Allow-list hydration / over-post ignored / client-id rejected (security.md)
- Every Testing helper across the suite (testing.md)

## Completeness audit

### New coverage added (vs the current 27 docs)

- MorphToMany (polymorphic to-many) — entirely undocumented before — full coverage in relations.md + related-endpoints.md
- The full AbstractRelation policy surface: withoutLinks, dataOnlyWhenLoaded, cannotReplace/cannotRemove/cannotAdd, withoutRelatedEndpoint/withoutRelationshipEndpoint, paginate()/pagination() to relations.md, related-endpoints.md, relationship-mutation.md, pagination.md
- The field composition/cross-field helpers constrain(...)/sequentially(...)/atLeastOneOf(...)/compareWith(field, Comparison) on every field to fields.md; the constraints Sequentially/AtLeastOneOf/CompareField + Comparison enum to constraints.md
- Str::email(bool $strict) signature; BelongsToMany::fields(Closure|array) closure form + pivotFields() accessor to field-types.md, relations.md
- uriType / static $uriType (URI segment distinct from type) to resources.md
- PolymorphicSerializer + RelationInterface::resolveSerializer; UriTypeAwareInterface; SerializerResolverAwareInterface; RelationshipLoadStateInterface to serializers.md
- PaginatorInterface::window() + WindowInterface/OffsetWindow (the push-down seam) and PageInterface::profile() (the omitted third method) to pagination.md
- NoContentResponse (the sixth response VO), AbstractResponse::withStatus() (the 201/non-default mechanism, replacing the stale set-it-downstream guidance), and RelatedResponse::fromPage() to responses.md, related-endpoints.md
- The corrected batch SortHandlerInterface::apply(list<SortDirective>) signature + the SortDirective VO (replacing the stale per-sort signature) to sorts.md, adapters.md
- AbstractHydrator::validateDomainObject() in the hook table; AbstractHydrator implements UpdateRelationshipHydratorInterface (hydrateRelationship); the narrower AbstractCreateHydrator/AbstractUpdateHydrator bases to hydrators.md
- The capability-composition thesis (serializer/hydrator/relations/provider/persister as independent capabilities) with register() override-resolution and registerSerializerHydrator() standalone + NoResourceRegistered boundary + HydratorResolverInterface to capability-composition.md
- The two currently-missing exceptions AdditionProhibited (403) and FilterParamUnrecognized (400), and a regenerated 37-class catalogue (no hard-coded stale count) to errors-and-exceptions.md
- withRelationshipLoadState() on the Server (the load-state predicate injection) to server.md
- A dedicated related-endpoints page + a dedicated relationship-mutation page (the read/write relationship endpoints, previously only implied across server/responses/spec-compliance)
- A dedicated sparse-fieldsets-and-includes page (the Phase-1-deferred include coverage, previously only in spec-compliance) to sparse-fieldsets-and-includes.md
- A dedicated links-and-meta how-to (extracted from concepts into actionable form) to links-and-meta.md
- A dedicated ids page (Id field + the client-generated-id lifecycle) to ids.md
- The whole worked music-catalog example app + its CI test as the single source of truth backing every page (examples/music-catalog/)

### Intentionally dropped

- release-readiness.md / v1-readiness-review.md / v1-security-negotiation-review.md — internal pre-release review artifacts, not user-facing docs; intentionally not carried into the public IA (they stay as maintainer notes, outside docs/)
- The duplicated half of middleware-order.md — merged into a single middleware.md ordering treatment to remove the documented inconsistency (no coverage lost; the unique profiles-flow-through-untouched, the response-VO caveat, and the PSR-17-factories/body-size notes are preserved in middleware.md)
- The standalone errors.md/exceptions.md split — merged into errors-and-exceptions.md (errors.md owned propagation, exceptions.md owned the catalogue; one page keeps the boundary crisp without duplicating the throw model). No coverage lost.
- Naming of @internal classes as public API: MediaType (security/content-negotiation), QueryParam, the transformer/document/Transformation engine classes, Internal namespaces — described by behaviour or named only as a labelled architecture aside, never as a user capability (deliberate exclusion per the grounding rule)
- serializeWithoutRequest() — @internal on AbstractField, excluded
- Historical framing dropped in the greenfield rewrite: the opaque-id/payload-indirection-removed note in adapters; the no-extensions-in-this-release version-pinned phrasing (kept as a behaviour statement: empty-by-default supported set); hard-coded exception counts (33)
- The articles/blog worked theme throughout — replaced wholesale by the music-catalog example (a re-theme, not a coverage drop)
- The README non-self-sufficient one-shot quick example — replaced by index.md explicitly-a-taste snippet that forward-links to the runnable getting-started walkthrough (avoids the copy-paste-hits-500 trap)

## Adversarial IA critique — corrections to apply before build

**Verdict:** Close to ready — build it after three corrections, but it is NOT yet safe to hand to a docs author as-is. The IA is impressively faithful to src/: every capability count I spot-checked is exact (37 concrete exceptions, 6 response VOs, 9 operation VOs, 4 paginators + 4 page types, the 7-method SerializerInterface, the complete relation policy surface on AbstractRelation, the full 34-VO constraint vocabulary, all 9 built-in filters, all 16 field types, the 6-case Comparison enum, the RequestValidator/ResponseValidator method names). The drift the rewrite targets is real and the fixes are correctly scoped. Progressive disclosure is mostly sound and internals are correctly excluded (serializeWithoutRequest @internal and MediaType @internal both verified). MUST-FIX before build: (1) HIGH — the example-app spec documents default-included relationships 'via fields', but the only mechanism in src is OVERRIDING getDefaultIncludedRelationships(); the example as specced will not compile/work and the include page will document a non-existent affordance. (2) HIGH — the '37-class catalogue regenerated from src/Exception/' will silently DROP UnsupportedFilter/UnsupportedSort, two thrown 500 exceptions that live outside src/Exception/ and are referenced as capabilities on four other pages — reintroducing drift. (3) MEDIUM — the CompareField directional worked example (endDate > startDate) has no directional referent in the example app (only an equality check on users), so a backing test cannot extract it; add a real date-range pair. SHOULD-FIX: self-contain the filters/sorts worked examples so they don't forward-depend on adapters.md (execution semantics deferred six pages), and sharpen the concepts.md vs links-and-meta.md split (data-model vs how-to) to stop double-coverage. Everything else is low-severity discoverability polish (section breadcrumbs, single-owner rule for handle()-vs-dispatch()). With the two HIGH items and the CompareField example fixed, the matrix backs every page claim and nothing in the public surface is orphaned.


### HIGH

- **[gap]** The example-app spec claims default-included relationships are configured 'via fields' — exampleArtifact reads `AlbumResource.php (getDefaultIncludedRelationships via fields)` and the demonstrates list implies a field/relation fluent declaration. Grounding: `AbstractResource::getDefaultIncludedRelationships()` returns `[]` and there is NO field- or relation-level fluent method to declare a default include (no `defaultInclude`/`includeByDefault` anywhere in src/Resource/Field/). The only mechanism is OVERRIDING the method. Writing the example 'via fields' will produce code that does not work, and `sparse-fieldsets-and-includes.md` will document a non-existent affordance.
  - *Fix:* Correct the exampleArtifact and page outline: default includes are set by OVERRIDING `getDefaultIncludedRelationships()` on the resource/serializer (returns a list of relation names), not by a field declaration. Show the override explicitly on AlbumResource (e.g. return ['artist']). Adjust the demonstrates bullet accordingly.
- **[orphaned-capability]** The exception catalogue is positioned as a single '37-class catalogue' regenerated from `src/Exception/`, but `UnsupportedFilter` and `UnsupportedSort` — both AbstractJsonApiException subclasses that render as real 500 error documents and are explicitly listed as capabilities on filters.md/sorts.md/adapters.md — live OUTSIDE src/Exception/ (in src/Resource/Filter/ and src/Resource/Sort/). A docs author regenerating the table 'from src/Exception/' will silently drop two thrown exceptions the rest of the IA references, reintroducing exactly the drift this rewrite is meant to kill.
  - *Fix:* State the catalogue scope as 'all JsonApiExceptionInterface implementations' not 'src/Exception/', and explicitly enumerate the two out-of-directory members (UnsupportedFilter UNSUPPORTED_FILTER 500, UnsupportedSort UNSUPPORTED_SORT 500). Either fold them into the catalogue table with a note on their namespace, or cross-reference them from the query-param row. Do not anchor the count to a directory listing.

### MEDIUM

- **[disclosure-order]** Progressive-disclosure / forward-reference hazard in the Querying arc. filters.md and sorts.md (early, in Querying) promise WORKED examples that show request->response outcomes ('show the request and the response', singular() returning one-or-null, WhereHas returning albums-with-tracks), but the actual EXECUTION semantics (ArrayFilterHandler operator behaviour, ArraySortHandler comparator, the fold-over-query loop) are deferred to adapters.md, which sits late in Cross-cutting. A reader on filters.md cannot see why `operator('like')` matches, or run anything, without jumping forward six pages. The metadata/execution split is real in src (handlers are separate), but the docs sequencing makes the first worked example depend on an unread page.
  - *Fix:* On filters.md/sorts.md, inline a minimal 'how this executes' aside (one paragraph: in this example app a reference ArrayFilterHandler/ArraySortHandler applies it; full handler-authoring in adapters.md) so the worked example is self-contained, and make the cross-link a 'go deeper' not a 'prerequisite'. Alternatively move the reference-handler worked snippet earlier. Confirm the example test for FilteringTest/SortingTest exercises the in-memory handler so the snippet is real.
- **[gap]** `registerSerializerHydrator(string $type, ...)` keys on a plain string type, whereas `register(string $resource, ...)` keys on a class-string Resource. The capability-composition.md outline says 'a bare pair under an explicit type' which is correct, but the example-app spec's charts entry and the bootstrap artifact don't make the type-string-vs-class-string asymmetry explicit, and operations/routing for a standalone type (how a Target's type maps to a bare serializer with no Resource and no uriType) is the subtle part. A reader composing a standalone type needs to see the type-string is the registry key AND the URI segment source (via UriTypeAwareInterface).
  - *Fix:* In capability-composition.md, explicitly contrast the two registration signatures (class-string vs type-string key) and show how a standalone serializer's URI segment is resolved (UriTypeAwareInterface, else the type string). Ensure the ChartSerializer example demonstrates the operation allow-list = fetch-only and how its Target is produced by the router, since there's no Resource to derive routes from.
- **[example-app]** The Comparison-enum cross-field direction nuance is asserted but the example placement may under-back it. constraints.md promises a WORKED CompareField example 'this-field on the LEFT (endDate GreaterThan startDate)', but the example app's only CompareField demo is on users (password/passwordConfirm per the spec), which is an equality check — it does not exercise a directional comparator (GreaterThan/LessThan) where left/right order is observable. The 'this-field-on-the-left' worked claim has no directional referent in the example app.
  - *Fix:* Add a directional CompareField referent to the example app — e.g. a date-range field pair on albums or playlists (startDate/endDate with GreaterThan) — so the 'this-field on the LEFT' worked example is extracted from a real, test-verified case, not invented in prose. Update the AlbumResource/UserResource exampleArtifacts and ConstraintMetadataTest accordingly.

### LOW

- **[discoverability]** `getDefaultIncludedRelationships` lives on BOTH AbstractResource and SerializerInterface (it's method #6 of the 7-method serializer contract), and the IA documents it in two places without reconciling them: serializers.md lists getDefaultIncludedRelationships as a serializer method, and sparse-fieldsets-and-includes.md treats default-include as a resource/include concept. A reader could miss that overriding it on a hand-written serializer is the same lever as on a resource.
  - *Fix:* Add a one-line cross-link: note in sparse-fieldsets-and-includes.md that default includes are the `getDefaultIncludedRelationships()` member of the serializer contract (and thus overridable on AbstractResource OR a custom serializer), cross-referencing serializers.md. Keeps the single mechanism discoverable from both entry points.
- **[redundancy]** Redundancy risk between concepts.md and links-and-meta.md. concepts.md's outline already covers 'Links: bare-string vs link-object form; grouped into keyed containers with a baseUri prepend; reserved relations + custom' AND the jsonapi object AND meta — and links-and-meta.md re-covers 'Link forms + containers + baseUri prepend', 'meta at all levels', 'jsonapi version'. The same link-container/baseUri material is enumerated as a capability on both pages. Without a sharp split rule a reader meets it twice and the matrix double-counts.
  - *Fix:* Make the split explicit and mechanical: concepts.md = the link/meta DATA MODEL (what the shapes are, read-only mental model); links-and-meta.md = the HOW-TO (which wither/override to call to set them). State this boundary in both page roles, and ensure the capabilityMatrix attributes the model row to concepts.md and the setter rows to links-and-meta.md only.
- **[redundancy]** architecture.md and server.md both own 'handle() vs dispatch()' as a worked/per-item capability, and operations.md additionally owns the OperationContext httpRequest-null 'two paths' worked example. That's three pages narrating the same two-entry-point distinction. It's legitimately relevant to each, but the matrix lists handle()-vs-dispatch() as a worked example on server.md AND as part of architecture.md's prose AND operations.md frames the same split via OperationContext — risking three near-duplicate treatments.
  - *Fix:* Designate ONE canonical worked treatment (server.md, since dispatch() requires an OperationHandlerInterface inner handler that's a Server concern) and have architecture.md (conceptual framing) and operations.md (the httpRequest()===null consequence) explicitly defer to it with a 'see server.md' link rather than re-deriving it. Note the single-owner rule in each page role.
- **[discoverability]** The IA has no top-level navigation/index ordering artifact beyond per-page `section` tags. With 30 pages across 6 sections and dense cross-linking, a reader landing mid-set (e.g. via search on relationship-mutation.md) has no visible 'you are here / prerequisites' breadcrumb. The narrative describes the six-arc journey but no page outline carries an explicit 'before this, read X' prerequisite line, so the progressive-disclosure order is an authoring intention not a reader-visible structure.
  - *Fix:* Add a section-hub convention: each section's first page (or a short section index) lists its pages in reading order, and every page opens with a one-line 'assumes you've read: concepts.md, resources.md' prerequisite breadcrumb. This makes the simplest-first ordering enforceable and recoverable for a reader who arrives out of sequence.
- **[other]** The plan's 'dropped' list claims `serializeWithoutRequest()` is '@internal on AbstractField, excluded' and MediaType is '@internal' — both VERIFIED CORRECT against src (serializeWithoutRequest carries @internal at AbstractField.php:449; MediaType at src/Request/MediaType.php carries @internal). No fix needed; flagging as a positive confirmation that the internal/public discrimination rule was applied accurately, since the rest of the audit depends on that discipline holding.
  - *Fix:* No change required — confirmation only. Keep serializeWithoutRequest and MediaType out of capability claims as planned; MediaType may be named once as the behaviour engine behind RequestValidator on content-negotiation.md (as the plan does) without elevating it to public API.