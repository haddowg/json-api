# Validation

A [Resource class](resources.md)'s fields carry validation **metadata** — `->required()`,
`->maxLength()`, `->in([...])`, and so on. The core library never executes these
constraints against data; they are declarative facts about a field that two
consumers read. The optional JSON Schema compiler turns the structural subset into
a per-resource JSON Schema that tightens request validation, and framework
adapters translate the full vocabulary into their native validator rules. This
page describes the constraint vocabulary, the create/update context model, the
JSON Schema compiler, and the opt-in document-validation layer.

## Constraints are metadata

Every constraint is a `final readonly` value object implementing
`Resource\Constraint\ConstraintInterface`, whose single method `context(): Context` reports
when the constraint applies. You rarely construct one directly — the fluent
[field](fields.md) builders add them for you (`Str::make('title')->required()
->maxLength(200)` appends a `Required` and a `MaxLength`). The library does not run
them; it exposes them. Two readers consume the metadata:

- the [JSON Schema compiler](#per-resource-schemas), for structural request
  validation against the JSON:API document schema;
- [framework adapters](adapters.md), for full, value-level validation in their
  native validator.

## The constraint vocabulary

| Group | Constraints | JSON Schema keyword(s) |
|---|---|---|
| Presence | `Required`, `Nullable` | `required`, nullable `type` union |
| Numeric bounds | `Min`, `Max`, `ExclusiveMin`, `ExclusiveMax`, `MultipleOf` | `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf` |
| String length | `MinLength`, `MaxLength` | `minLength`, `maxLength` |
| Array bounds | `MinItems`, `MaxItems`, `UniqueItems` | `minItems`, `maxItems`, `uniqueItems` |
| Object bounds | `MinProperties`, `MaxProperties` | `minProperties`, `maxProperties` |
| Pattern / enum | `Pattern`, `In`, `NotIn` | `pattern`, `enum`, `not`/`enum` |
| String formats | `EmailFormat`, `UrlFormat`, `UuidFormat`, `IpFormat`, `SlugFormat` | `format` (`email`/`uri`/`uuid`/`ipv4`/`ipv6`) or `pattern` |
| Date bounds | `Before`, `After`, `Between` | `formatMinimum` / `formatMaximum` (fixed bounds only) |
| Composition | `Each`, `When` | `items` (`Each`); `When` skipped |
| Relationships | `RelationshipType` | linkage `type` `enum` |

`Each` applies a wrapped set of constraints to every item of an array.
`RelationshipType` constrains a [relationship](fields.md#relationships)'s
resource-identifier `type` member(s) to an allowed set — for a polymorphic
relationship, every permitted inverse type.

`When` applies its wrapped constraints only when a closure returns true for the
value under validation. Its closure cannot round-trip to JSON Schema, so the
compiler skips it (see [below](#when-and-custom-constraints)); an adapter that
executes validation evaluates it.

For rules the built-in vocabulary doesn't cover, implement your own
`ConstraintInterface` value object and attach it with the field's `constrain()`
method — a typed extension point, no opaque `id`/`payload` indirection. An adapter
translates it by matching on its class (the JSON Schema compiler skips constraints
it doesn't recognise).

## Create and update contexts

Every constraint carries a `Resource\Constraint\Context` declaring whether it
applies on **create** (POST) requests, **update** (PATCH) requests, or both:

```php
final readonly class Context
{
    public function __construct(public bool $onCreate = true, public bool $onUpdate = true) {}
    public static function always(): self;      // both (the default)
    public static function onlyCreate(): self;
    public static function onlyUpdate(): self;
    public function appliesTo(bool $creating): bool;
}
```

Constraints default to `always()`. Scope them with the per-field `onCreate()` /
`onUpdate()` builders (which build a constraint set under a single context) or,
for the common case, the `requiredOnCreate()` / `requiredOnUpdate()` shortcuts.

### `Required` semantics

`Required` means **present and non-empty** — but its strictness depends on the
context, matching JSON:API update semantics:

- On **create** (POST) the field must be present and non-empty.
- On **update** (PATCH) absence means "no change" (a partial update is valid), so
  a plain `->required()` does **not** force the member to appear; only an
  explicitly-supplied empty value fails.

If you need a member to be present on a PATCH too, scope a `Required` to the update
context with `->requiredOnUpdate()`; `->requiredOnCreate()` is the create-only
form. `->required()` applies in both contexts (with the per-context strictness
above).

`Nullable` widens the allowed value to include an explicit `null` (a nullable
`type` union in JSON Schema); it is independent of presence.

## Per-resource schemas

`Validation\SchemaCompiler` turns a resource's field + constraint metadata into a
decoded draft-2020-12 JSON Schema (a `stdClass` tree) that **tightens** the
JSON:API base schema for one resource type in a given context:

```php
use haddowg\JsonApi\Validation\SchemaCompiler;

$compiler = new SchemaCompiler();
$createSchema = $compiler->compile($articleResource, creating: true);   // POST
$updateSchema = $compiler->compile($articleResource, creating: false);  // PATCH
```

The output constrains only `data.attributes` and `data.relationships` (plus the
per-context `required` arrays); it does not restate base members. `creating: true`
emits the POST schema, where `Required` / `requiredOnCreate` contribute to
`required`; `creating: false` emits the PATCH schema, where absent members are
allowed and only `requiredOnUpdate` and supplied values are constrained. `When`
and unrecognised custom constraints are skipped, and date bounds round-trip only when the bound is a fixed
`\DateTimeInterface` (a closure bound is evaluated at runtime by an adapter, not in
the schema).

The compiled schema drops straight into `DocumentValidator`'s composition list (no
validator API change) — you rarely call the compiler yourself, because
[`RequestValidationMiddleware`](middleware.md) appends the compiled schema for the
request's resource type automatically.

## Document validation

`Validation\DocumentValidator` validates a decoded JSON:API document against the
JSON:API JSON Schema, optionally augmented by additional schemas (profile
fragments and per-resource compiled schemas):

```php
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;

$validator = new DocumentValidator(new VendoredSchemaProvider());

$validator->validateRequest($decodedBody);   // throws RequestBodyInvalidJsonApi (400) on a violation
$validator->validateResponse($decodedBody);  // throws ResponseBodyInvalidJsonApi (500) on a violation
```

Both methods take an optional `array $additionalSchemas` of decoded schema objects
to compose. A failure throws a typed [`JsonApiExceptionInterface`](exceptions.md) carrying
one violation per JSON Schema error, each with the JSON Pointer of the offending
location — so the existing [error handler](errors.md) renders it for free.

Request and response schemas differ: a request's primary resource may omit its
`id` (a client-generated resource) and may carry a `lid`, whereas a response's
resource requires `type` + `id`. The `SchemaProviderInterface` exposes both
roots; the default `VendoredSchemaProvider` loads the JSON:API 1.1 schemas
vendored under `resources/schemas/` (the response schema is a byte-faithful copy of
the upstream VGirol JSON:API schema).

### The optional `opis/json-schema` dependency

`DocumentValidator` is backed by [`opis/json-schema`](https://opis.io/json-schema/),
which is an **optional** dependency: it is declared under `require-dev` and
`suggest`, never `require`. The core library has no hard dependency on it.
Constructing a `DocumentValidator` fails fast if the package is absent, so wiring
either validation middleware on a server makes the missing dependency obvious
immediately. Install it where you want validation:

```bash
composer require --dev opis/json-schema
```

### Profile fragments

A [profile](profiles.md) can extend validation while it is in scope. Implement
`Validation\SchemaContributingProfileInterface` (which extends `ProfileInterface`) and
return a decoded schema fragment from `schemaFragment()`; the `DocumentValidator`
composes that fragment with the base schema (via `allOf`) for requests and
responses that have the profile in scope. A fragment can both add constraints and
permit profile-reserved top-level members the base schema would otherwise reject.

## The validation middleware

The two PSR-15 [validation middleware](middleware.md) wrap `DocumentValidator` into
the request lifecycle, and are **per-server opt-in** (intended for dev/CI, not
production — add them only where you want them):

- `RequestValidationMiddleware(ServerInterface $server, DocumentValidator
  $validator)` validates a present request body against the request schema (plus
  the compiled per-resource schema and any in-scope profile fragments) and throws
  `RequestBodyInvalidJsonApi` (`400`) on a violation.
- `ResponseValidationMiddleware(ServerInterface $server, DocumentValidator
  $validator, bool $throwOnViolation = true, ?LoggerInterface $logger = null)`
  validates the rendered response document. A failing response is a server bug, so
  by default it throws `ResponseBodyInvalidJsonApi` (`500`); pass
  `$throwOnViolation = false` to log and pass through instead.

See [Middleware](middleware.md#optional-validation-middleware-devci) for placement
in the chain.

## `When` and custom constraints

`When` carries logic the JSON Schema vocabulary cannot express — a PHP closure
gating a constraint set — so it **does not round-trip** to JSON Schema; the
compiler skips it and an [adapter](adapters.md) evaluates it in its native
validator.

For anything else the built-in vocabulary doesn't model, implement your own
`ConstraintInterface` value object (carrying whatever typed config the rule needs)
and attach it with the field's `constrain()` method. It is skipped by the schema
compiler and translated by an adapter that recognises its class. This is the typed
replacement for an opaque, string-keyed escape hatch: the constraint *is* the
contract, and the adapter translator matches on its type. The structural
constraints still feed the JSON Schema check for free.

## Related pages

- [Resource classes](resources.md) — where fields and their constraints are declared.
- [Fields](fields.md) — the fluent builders that add constraints.
- [Middleware](middleware.md) — the optional request/response validation middleware.
- [Adapters](adapters.md) — translating the full constraint vocabulary to a native validator.
- [Profiles](profiles.md) — profiles that contribute schema fragments.
