# Schema validation

With the optional `opis/json-schema` package installed, you can validate every
JSON:API request and response against the JSON:API 1.1 JSON Schema, augmented by
your resources' own field/constraint metadata and any in-scope profile fragments.
This catches structural mistakes — a missing `type`, a relationship sent as an
attribute, a profile member the base schema would reject — at the document level,
with a JSON Pointer to each offending location.

Treat this as a **dev/CI conformance aid, not a runtime firewall.** It exists to
surface bugs in your serializers and your clients during development and in your
pipeline; it is per-server opt-in and is not a substitute for the security
posture described in [security](security.md). The constraint vocabulary that
actually rejects bad client input at runtime is the validator described in
[validation](constraints.md) — schema validation is a coarse structural net layered
on top.

## Turning it on

The validator needs `opis/json-schema`, which the library only **suggests** (never
requires). Add it to the project where you want validation:

```bash
composer require --dev opis/json-schema
```

Then build a [`DocumentValidator`](../src/Validation/DocumentValidator.php) over a
[`SchemaProviderInterface`](../src/Validation/SchemaProviderInterface.php). The
default provider ships the vendored JSON:API 1.1 schemas:

```php
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;

$validator = new DocumentValidator(new VendoredSchemaProvider());
```

Constructing the `DocumentValidator` **fails fast** if `opis/json-schema` is
absent, so wiring tells you immediately rather than at the first request. The
provider's schemas are registered into one reusable `opis` validator at
construction and compiled-and-cached on first use, so validation is cheap across
requests.

**The common path:** in a PSR-15 server you usually don't call the validator
directly — you add the two optional middleware that drive it off the request and
response automatically (see [Wiring it into the middleware
chain](#wiring-it-into-the-middleware-chain) below). The rest of this page —
`validateRequest`/`validateResponse`, the schema roots, the `unevaluatedProperties`
relocation, `SchemaCompiler` — is the "how it works / customize it" detail beneath
that.

## Validating a document

The validator exposes two methods, because request and response bodies differ:

| Method | Schema root | On failure | Status |
|---|---|---|---|
| `validateRequest(mixed $document, array $additionalSchemas = [])` | request schema — a primary resource may omit `id` (client-generated) and may carry a `lid` | [`RequestBodyInvalidJsonApi`](../src/Exception/RequestBodyInvalidJsonApi.php) | `400` |
| `validateResponse(mixed $document, array $additionalSchemas = [])` | response schema — a resource requires `type` + `id` | [`ResponseBodyInvalidJsonApi`](../src/Exception/ResponseBodyInvalidJsonApi.php) | `500` |

The `$document` is the decoded body (an array or `stdClass` tree), not raw JSON.
A request may legitimately ship a client-generated resource with no `id`:

```php
// Passes request validation: no id, a lid is allowed.
$validator->validateRequest([
    'data' => ['type' => 'albums', 'lid' => 'tmp-1', 'attributes' => ['title' => 'New']],
]);
```

Each failure carries **one violation per `opis` leaf error**, each with the JSON
Pointer (`source.pointer`) of the offending location. The error cap is raised to
20 so a malformed document surfaces several problems at once rather than only the
first. The typed exceptions are full
[`JsonApiExceptionInterface`](../src/Exception/JsonApiExceptionInterface.php)
errors, so the [error-handler middleware](errors-and-exceptions.md) renders them for free — a
`400` (request) or `500` (response) error document, each `Error` carrying the
pointer:

```php
use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;

try {
    $validator->validateRequest(['data' => ['attributes' => ['title' => 'New']]]);
} catch (RequestBodyInvalidJsonApi $exception) {
    $exception->getStatusCode();   // 400
    $exception->validationErrors;  // [['message' => '…', 'property' => '/data'], …]
}
```

A failing **response** is a server bug, not a client error — hence the `500`. Use
it in CI to assert your own serializers emit conformant documents.

## The schemas: request vs response roots

[`VendoredSchemaProvider`](../src/Validation/VendoredSchemaProvider.php) loads the
JSON:API 1.1 schemas vendored under `resources/schemas/` and exposes the two roots
the validator needs:

- `responseSchema()` / `responseSchemaId()` — the base schema (resources require
  `type` + `id`).
- `requestSchema()` / `requestSchemaId()` — relaxes the primary resource to allow
  an omitted `id` and a `lid`. Its cross-document `$ref`s reach back into the base,
  so both schemas are registered into the validator's resolver.

The provider applies one transformation the validator needs: it **strips the
document-root `unevaluatedProperties`** keyword from the response schema so the
`DocumentValidator` can re-apply it on its own composite. Nested
`unevaluatedProperties` are left intact. To validate against a different schema set
(a vendored fork, a tightened in-house variant), implement
`SchemaProviderInterface` yourself and pass it to the `DocumentValidator`.

## Composing in extra schemas: the `allOf` + `unevaluatedProperties` relocation

In practice: you can pass extra schema fragments to **extend** (never override)
what's allowed — e.g. a profile adding a top-level member. The mechanism below is
how that works; skip it unless you author fragments.

Every validation builds a synthetic composite root:

```
{ "allOf": [ {"$ref": <base/request id>}, …additional ], "unevaluatedProperties": false }
```

Because the relocated `unevaluatedProperties: false` lives on the **composite** — not
on the base schema's root — an additional schema's top-level `properties` **extend**
the set of permitted members rather than colliding with the base schema's own
closed set. This is what lets a profile fragment *add* a top-level member the base
schema alone would reject:

```php
// A fragment declaring a profile-reserved top-level member.
$fragment = \json_decode('{"properties":{"aggregations":{"type":"object"}}}', false);

$document = ['data' => ['type' => 'albums', 'id' => '1'], 'aggregations' => ['count' => 3]];

// Base alone rejects the unknown top-level member…
$validator->validateResponse($document);            // throws ResponseBodyInvalidJsonApi

// …but composed with the fragment, it is accepted.
$validator->validateResponse($document, [$fragment]);
```

A fragment **relaxes by extension, never overrides**: the base constraints still
apply, so a document that breaks a base rule (e.g. carrying both `data` and
`errors`) fails even with a fragment composed in.

## Per-resource schemas: `SchemaCompiler`

[`SchemaCompiler`](../src/Validation/SchemaCompiler.php) turns one
[resource](resources.md)'s field + constraint metadata into a draft-2020-12
fragment that **tightens** the base schema for that type — exactly the shape
`validateRequest()`'s `$additionalSchemas` list takes. It only constrains
`data.attributes` / `data.relationships`; it never restates base members or touches
`unevaluatedProperties` (the composite owns that):

```php
use haddowg\JsonApi\Validation\SchemaCompiler;

$compiler = new SchemaCompiler();

$createSchema = $compiler->compile(new AlbumResource(), creating: true);
$updateSchema = $compiler->compile(new AlbumResource(), creating: false);

$validator->validateRequest($document, [$createSchema]);
```

The context flag drives the **create vs update** split, mirroring the constraint
contexts in [validation](constraints.md):

- `creating: true` (POST) — `Required` / `requiredOnCreate` fields become
  `required[]`.
- `creating: false` (PATCH) — absent members are allowed; only `requiredOnUpdate`
  fields and the values actually supplied are constrained.

It maps the round-trippable constraint vocabulary directly onto JSON Schema
keywords — `maxLength`, `minLength`, `minItems`/`maxItems`/`uniqueItems`,
`minimum`/`maximum`, `pattern`, `enum` (for `In`), the format keywords
(`email`, `uri`, `uuid`, `ipv4`/`ipv6`, `date`/`time`/`date-time`), `multipleOf`,
and so on. `Nullable` widens a field's `type` to allow `null`; `Each` compiles to
`items`; a structured [`Map`](fields.md)'s children compile recursively into nested
`properties`. Composition constraints map too: `Sequentially` merges its inner
schemas into the field, and `AtLeastOneOf` becomes an `anyOf`. A relationship field
constrains only the linkage `type` (to its declared related types); cardinality is
left to the base schema.

Some constraints **deliberately do not round-trip** and are silently skipped — JSON
Schema cannot express them, and the runtime [validator](constraints.md) still
enforces them:

| Skipped | Why |
|---|---|
| `When` | an opaque closure condition — no JSON Schema analogue |
| `CompareField` | cross-property comparison has no draft-2020-12 expression |
| Closure date bounds (`Before`/`After`/`Between` with a `\Closure`) | the bound is resolved per request, so it cannot be baked into a static schema (a fixed `\DateTimeInterface` bound *does* round-trip via `formatMinimum`/`formatMaximum`) |

For example, [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)'s
`releasedAt` uses a closure bound (`->before(static fn() => new \DateTimeImmutable())`)
and `availableUntil` uses `compareWith(...)` — both are absent from the compiled
schema and only run at hydration time. Its `title` (`->required()->maxLength(200)`),
`availableFrom` (`->nullable()`) and the `releaseInfo` `Map` all compile in full.

## Profiles that contribute a fragment

A [profile](profiles.md) can augment validation while it is in scope by
implementing
[`SchemaContributingProfileInterface`](../src/Validation/SchemaContributingProfileInterface.php) —
an opt-in extension of `ProfileInterface`:

```php
public function schemaFragment(): ?object;
```

Return a decoded draft-2020-12 fragment, or `null` to contribute nothing. When the
profile is **in scope for the request** (server-registered and requested/required
via the `Accept`/`Content-Type` `profile` parameter or the `profile` query
parameter), the `DocumentValidator` composes the fragment via `allOf`. Because the
composite owns `unevaluatedProperties`, a fragment can both *add* constraints (e.g.
require a profile-reserved member to have a given shape) and *permit*
profile-reserved top-level members the base schema would reject — the relocation
described above. A profile that contributes nothing simply does not implement the
interface, and base validation is unchanged.

## Wiring it into the middleware chain

In a PSR-15 server, two optional middleware drive the validator off the request and
response automatically — see [middleware](middleware.md) for the full chain. Both
take the [`ServerInterface`](../src/Server/ServerInterface.php) (to gather in-scope
profile fragments) and the injected `DocumentValidator`. Add them **only where you
want validation** (dev/CI); they are per-server opt-in.

- [`RequestValidationMiddleware`](../src/Middleware/RequestValidationMiddleware.php)
  runs **after** body parsing and **before** the handler. A bodyless request (GET,
  bodyless DELETE) passes straight through; a present body is validated and a
  failure throws `RequestBodyInvalidJsonApi` (`400`).

  ```php
  use haddowg\JsonApi\Middleware\RequestValidationMiddleware;

  new RequestValidationMiddleware($server, $validator);
  ```

- [`ResponseValidationMiddleware`](../src/Middleware/ResponseValidationMiddleware.php)
  validates the **outgoing** `application/vnd.api+json` document as the response
  unwinds. Placement is just inside the [error handler](errors-and-exceptions.md) and outside
  negotiation/body-parsing. By default it **throws** `ResponseBodyInvalidJsonApi`
  (`500`) so a serializer bug is loud in dev/CI; pass `throwOnViolation: false`
  (with an optional `LoggerInterface`) to downgrade to logging and pass the
  response through unchanged — a production-soak mode.

  ```php
  use haddowg\JsonApi\Middleware\ResponseValidationMiddleware;

  // Loud in CI:
  new ResponseValidationMiddleware($server, $validator);

  // Soak in production: log violations, do not throw.
  new ResponseValidationMiddleware($server, $validator, throwOnViolation: false, logger: $logger);
  ```

Each middleware gathers the in-scope profile fragments itself, so a profile's
`schemaFragment()` is composed automatically whenever that profile is applied to
the request.

## Next / see also

- [validation](constraints.md) — the runtime constraint vocabulary that rejects bad
  input (this page's structural net does not replace it).
- [profiles](profiles.md) — the profile contract, and `SchemaContributingProfileInterface`.
- [middleware](middleware.md) — where the two validation middleware sit in the chain.
- [errors](errors-and-exceptions.md) — how the typed exceptions render to error documents.
- [security](security.md) — why this is a dev/CI aid, not a runtime firewall.
