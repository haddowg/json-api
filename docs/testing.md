# Testing

The library ships a small set of testing utilities in its **runtime** autoload —
not `require-dev` — so they are available in your application's test suite without
extra wiring. They are deliberately minimal: fluent assertions over a rendered
document, builders for requests and operations, and a one-line spec-compliance
check. There are no model factories, fixtures, database traits, or HTTP clients;
those belong to your application and its framework.

The assertion wrappers (`JsonApiDocument`, `JsonApiErrors`) and the
spec-compliance helper all accept the same four input shapes, so they slot into
whichever test style you use:

- a PSR-7 `ResponseInterface` (e.g. the result of `Server::handle()`);
- a raw JSON string;
- an already-parsed `array`;
- a [response value object](responses.md) — pass a `ServerInterface` (and
  optionally the originating request) so it can be rendered first.

## Asserting a document

`Testing\JsonApiDocument` wraps a `data`/`meta`/`links` document. Every assertion
returns `$this`, so checks chain; the lower-level accessors (`data()`,
`included()`, `meta()`, `links()`, `toArray()`) expose the raw structure for
ad-hoc inspection. Failures delegate to PHPUnit's `Assert`, so they read like any
other test failure.

```php
use haddowg\JsonApi\Testing\JsonApiDocument;

$response = $server->handle($request);

JsonApiDocument::of($response)
    ->assertHasType('articles')
    ->assertHasId('1')
    ->assertHasAttribute('title', 'JSON:API in PHP')
    ->assertHasRelationship('author', expectedType: 'authors', expectedId: '9')
    ->assertHasIncluded('authors', count: 1)
    ->assertHasLink('self', 'https://example.test/articles/1')
    ->assertProfileApplied('https://example.test/profiles/x');
```

The full assertion surface: `assertHasType`, `assertHasId`, `assertHasAttribute`
(value optional), `assertHasRelationship` (type/id optional), `assertHasIncluded`
(count optional) / `assertNotHasIncluded`, `assertHasMetaKey` / `assertMetaValue`,
`assertHasLink` (href optional), and `assertProfileApplied`.

## Asserting errors

`Testing\JsonApiErrors` wraps an error document (the same input shapes), exposing
the raw list via `errors()`:

```php
use haddowg\JsonApi\Testing\JsonApiErrors;

JsonApiErrors::of($response)
    ->assertCount(1)
    ->assertHasError(status: '422', pointer: '/data/attributes/title')
    ->assertHasErrorAt('/data/attributes/title')
    ->assertHasErrorWithCode('VALIDATION_FAILED');
```

`assertHasError()` matches an error by any combination of `status`, `pointer`
(read from `source.pointer`), and `code`.

## Building requests (the PSR-7 path)

`Testing\JsonApiRequestBuilder` builds a PSR-7 `ServerRequestInterface` carrying a
well-formed JSON:API request — for driving a `Server` end-to-end. The PSR-17
factories are injected (the package depends only on the PSR-17 interfaces), so it
works with any provider; the `Accept` header defaults to
`application/vnd.api+json`, and a body additionally sets `Content-Type` and the
parsed body.

```php
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiRequestBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();

$request = JsonApiRequestBuilder::post('https://example.test/articles', $psr17, $psr17)
    ->withResource('articles', attributes: ['title' => 'Hello', 'body' => 'World'])
    ->build()
    ->withAttribute(Target::class, new Target('articles')); // your router would normally do this

$response = $server->handle($request);

JsonApiDocument::of($response)->assertHasType('articles');
```

The named constructors `get()` / `post()` / `patch()` / `delete()` pick the
method; `withResource()` sets the body's primary resource (type, optional id,
attributes, relationships), and `withQueryParam()`, `withProfile()`, and
`withHeader()` round out the request. Because routing is your framework's job, a
real `Server::handle()` test still needs a `Target` attached (here added inline;
in the getting-started example a small router middleware does it).

## Building operations (the dispatch path)

`Testing\JsonApiOperationBuilder` builds `JsonApiOperation` value objects for
`Server::dispatch()` — the programmatic path that skips PSR-7 and the middleware
chain entirely. A `ServerInterface` is required (for the operation context):

```php
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiOperationBuilder;

$operation = JsonApiOperationBuilder::create('articles', $server)
    ->withAttribute('title', 'Hello')
    ->withRelationship('author', type: 'authors', id: '9')
    ->build();

$response = $server->dispatch($operation); // a response value object

JsonApiDocument::of($response, $server)->assertHasType('articles');
```

The named constructors are `create()`, `update($type, $id, $server)`,
`fetch($type, $id, $server)`, and `delete($type, $id, $server)`. Body-carrying
verbs (create/update) assemble the request body from the declared
`withAttribute()` / `withRelationship()` / `withRelationships()` calls; the
bodyless verbs ignore them. Since `dispatch()` returns an unrendered response
value object, pass the server to `JsonApiDocument::of()` so it can render before
asserting.

## Spec compliance

`Testing\SpecCompliance::assert()` validates a document against the JSON:API 1.1
response schema and turns any violation into a PHPUnit failure listing each
offending pointer and message. The `AssertsSpecCompliance` trait exposes the same
check as a method on your test case:

```php
use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use PHPUnit\Framework\TestCase;

final class ArticleApiTest extends TestCase
{
    use AssertsSpecCompliance;

    public function testResponseIsCompliant(): void
    {
        $response = $server->handle($request);

        $this->assertJsonApiSpecCompliant($response);
    }
}
```

This is backed by the optional [`opis/json-schema`](validation.md) package (via
`DocumentValidator`), so install it in your test environment; it defaults to the
bundled `VendoredSchemaProvider`, and a custom `DocumentValidator` can be passed
to compose extra schemas. See [Validation](validation.md) for the underlying
machinery.

## Related pages

- [Server](server.md) — `handle()` vs `dispatch()`, the two paths these utilities exercise.
- [Responses](responses.md) — the value objects the assertion wrappers can render.
- [Validation](validation.md) — `DocumentValidator` and the JSON Schema layer behind `assertJsonApiSpecCompliant()`.
- [Getting started](getting-started.md) — a full request round-trip to test against.
