# Testing helpers

This page covers the small set of testing utilities the library ships so you can
assert over a rendered JSON:API document and drive a `Server` end-to-end from a
test — fluent document and error assertions, a request builder and an operation
builder, and a one-line spec-compliance check.

The utilities live in the **runtime** autoload (`haddowg\JsonApi\Testing\`), not
`require-dev`, so they are available in your application's test suite with no
extra wiring. They are deliberately minimal: there are no model factories,
fixtures, database traits, or HTTP clients — those belong to your application and
its framework. Everything here is example-driven, lifted from the music-catalog
[test suite](../examples/music-catalog/tests/) that backs the rest of these docs.

## The four accepted input shapes

`JsonApiDocument`, `JsonApiErrors`, and the spec-compliance helper all accept the
same four input shapes, so they slot into whichever test style you use:

- a PSR-7 `ResponseInterface` (e.g. the result of [`Server::handle()`](server.md));
- a raw JSON `string`;
- an already-parsed `array`;
- a [response value object](responses.md) (an `AbstractResponse`) — pass a
  `ServerInterface` (and optionally the originating request) so it can be
  rendered first.

That last shape is what lets the same assertions work against the unrendered
value objects returned by [`Server::dispatch()`](server.md) as well as the PSR-7
responses returned by `handle()`.

## Asserting a document

`Testing\JsonApiDocument` wraps a `data`/`meta`/`links` document. Every assertion
returns `$this`, so checks chain; the lower-level accessors expose the raw
structure for ad-hoc inspection. Failures delegate to PHPUnit's `Assert`, so they
read like any other test failure.

Here it is worked against a rendered `albums` document, from
[`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php):

```php
use haddowg\JsonApi\Testing\JsonApiDocument;

$response = $this->server()->handle($request);

JsonApiDocument::of($response)
    ->assertHasType('albums')
    ->assertHasId('1')
    ->assertHasAttribute('title', 'OK Computer');
```

The relationship, included-resource, link, meta, and profile assertions round out
the surface — for example, asserting a linkage type/id and that a compound
document carried the related resource:

```php
JsonApiDocument::of($response)
    ->assertHasRelationship('artist', expectedType: 'artists', expectedId: '9')
    ->assertHasIncluded('artists', count: 1)
    ->assertHasLink('self', 'https://music.example/albums/1')
    ->assertProfileApplied('https://music.example/profiles/timestamp');
```

The full assertion surface:

| Method | Asserts |
| --- | --- |
| `assertHasType(string $type)` | primary `data.type` |
| `assertHasId(string $id)` | primary `data.id` |
| `assertHasAttribute(string $name, mixed $expected = null)` | attribute present; value matches if a second argument is passed |
| `assertHasRelationship(string $name, ?string $expectedType = null, ?string $expectedId = null)` | relationship present; linkage type/id match if given |
| `assertHasIncluded(string $type, ?int $count = null)` | at least one (or exactly `$count`) included resources of `$type` |
| `assertNotHasIncluded(string $type)` | no included resources of `$type` |
| `assertHasMetaKey(string $key)` | top-level meta key present |
| `assertMetaValue(string $key, mixed $expected)` | meta key present with that value |
| `assertHasLink(string $rel, ?string $expectedHref = null)` | top-level link present; href matches if given |
| `assertProfileApplied(string $uri)` | `links.profile` advertises the profile |

The value-optional behaviour is exact: `assertHasAttribute` distinguishes
*present* from *equal to* by argument count (`\func_num_args()`), so passing
`null` as the second argument really does assert the value is `null` — pass no
second argument to assert presence only.

The raw accessors return the parsed structure when an assertion isn't enough:

```php
$doc = JsonApiDocument::of($response);

$doc->data();       // the primary `data` member as-is (map, list, or null)
$doc->included();   // list<mixed> of included resources
$doc->meta();       // array<string, mixed>
$doc->links();      // array<string, mixed>
$doc->toArray();    // the whole parsed document
```

## Asserting errors

`Testing\JsonApiErrors` wraps an error document (the same four input shapes),
matching errors by `status`, `source.pointer`, and `code`. From
[`RelationshipMutationTest`](../examples/music-catalog/tests/RelationshipMutationTest.php),
asserting the `403` raised when a client tries to replace a `cannotReplace()`
relationship:

```php
use haddowg\JsonApi\Testing\JsonApiErrors;

JsonApiErrors::of($response)
    ->assertHasError(status: '403', code: 'FULL_REPLACEMENT_PROHIBITED');
```

The surface:

| Method | Asserts |
| --- | --- |
| `assertCount(int $count)` | the document carries exactly `$count` errors |
| `assertHasError(?string $status = null, ?string $pointer = null, ?string $code = null)` | an error matches **every** non-null argument |
| `assertHasErrorAt(string $pointer)` | some error has that `source.pointer` |
| `assertHasErrorWithCode(string $code)` | some error has that `code` |
| `errors()` | the raw `list<array<string, mixed>>` for ad-hoc checks |

`assertHasError()` is the general one — pass any combination of `status`,
`pointer` (read from `source.pointer`), and `code`, and it asserts a single error
matches all of them at once. For a validation failure you might assert the
`422` and the pointer together:

```php
JsonApiErrors::of($response)
    ->assertCount(1)
    ->assertHasError(status: '422', pointer: '/data/attributes/title');
```

See [errors and exceptions](errors-and-exceptions.md) for the typed exceptions
behind these `status`/`code` values.

## Building requests (the `handle()` path)

`Testing\JsonApiRequestBuilder` builds a PSR-7 `ServerRequestInterface` carrying a
well-formed JSON:API request, for driving a `Server` end-to-end through
[`handle()`](server.md). The PSR-17 factories are injected (the package depends
only on the PSR-17 *interfaces*), so it works with any provider. The `Accept`
header defaults to `application/vnd.api+json`; supplying a resource body also sets
`Content-Type` and the parsed body, and any profiles are echoed in the media-type
`profile` parameter.

```php
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiRequestBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();

$request = JsonApiRequestBuilder::post('https://music.example/albums', $psr17, $psr17)
    ->withResource('albums', attributes: ['title' => 'In Rainbows', 'explicit' => false])
    ->build()
    ->withAttribute(Target::class, new Target('albums')); // your router would normally do this

$response = $server->handle($request);

JsonApiDocument::of($response)->assertHasType('albums');
```

The named constructors `get()` / `post()` / `patch()` / `delete()` pick the
method — each takes the URI plus a `ServerRequestFactoryInterface` and a
`StreamFactoryInterface`. The fluent setters:

| Method | Effect |
| --- | --- |
| `withResource(string $type, ?string $id = null, array $attributes = [], array $relationships = [])` | sets the primary `data` member; `$relationships` is keyed by name, each a `{ data: … }` map |
| `withQueryParam(string $key, string $value)` | appends to the query string **and** the parsed query params |
| `withProfile(string ...$uris)` | adds profile URIs to the media-type parameter |
| `withHeader(string $name, string $value)` | sets an arbitrary header |
| `build()` | assembles the `ServerRequestInterface` |

Because routing is your framework's job, a real `handle()` test still needs a
[`Target`](operations.md) attached. The builder does not route, so attach it
inline as above (in production a small router middleware does it — see the
example app's [`PathPrefixRouter`](../examples/music-catalog/src/Http/PathPrefixRouter.php)).

## Building operations (the `dispatch()` path)

`Testing\JsonApiOperationBuilder` builds `JsonApiOperationInterface` value objects
for [`Server::dispatch()`](server.md) — the programmatic path that skips PSR-7 and
the middleware chain entirely. A `ResolvingServerInterface` is required (for the
operation context); `Server` implements it, so pass the server under test:

```php
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiOperationBuilder;

$operation = JsonApiOperationBuilder::create('albums', $server)
    ->withAttribute('title', 'In Rainbows')
    ->withRelationship('artist', type: 'artists', id: '9')
    ->build();

$response = $server->dispatch($operation); // an unrendered response value object

JsonApiDocument::of($response, $server)->assertHasType('albums');
```

The named constructors are `create(string $type, $server)`,
`update(string $type, string $id, $server)`, `fetch(string $type, string $id, $server)`,
and `delete(string $type, string $id, $server)`. Body-carrying verbs
(`create`/`update`) assemble the request body from the declared setters; the
bodyless verbs (`fetch`/`delete`) ignore them.

| Method | Effect |
| --- | --- |
| `withAttribute(string $name, mixed $value)` | sets one body attribute |
| `withRelationship(string $name, string $type, string $id)` | sets a to-one linkage |
| `withRelationships(string $name, array $identifiers)` | sets a to-many linkage; `$identifiers` is `list<array{type, id}>` |
| `build()` | the operation value object |

Since `dispatch()` returns an **unrendered** response value object, pass the
server to `JsonApiDocument::of()` (its second argument) so it can render the value
object before asserting — this is the response-VO input shape from the
[four shapes](#the-four-accepted-input-shapes) above.

## Spec compliance

`Testing\SpecCompliance::assert()` validates a document against the JSON:API 1.1
response schema and turns any violation into a PHPUnit failure listing each
offending pointer and message. The `AssertsSpecCompliance` trait exposes the same
check as `assertJsonApiSpecCompliant()` on your test case — each music-catalog
suite mixes it in at its own class level (e.g.
[`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php))
and asserts every body compliant:

```php
use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use haddowg\JsonApi\Testing\JsonApiDocument;
use PHPUnit\Framework\Attributes\Test;

final class GettingStartedTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function fetchingASingleAlbumReturnsASpecCompliantDocument(): void
    {
        $response = $this->get('/albums/1');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasId('1')
            ->assertHasAttribute('title', 'OK Computer');
    }
}
```

Both forms accept the same four input shapes plus an optional `DocumentValidator`.
The check is backed by the optional [`opis/json-schema`](schema-validation.md)
package (through `DocumentValidator`), so install it in your test environment; it
defaults to the bundled `VendoredSchemaProvider`, and you can pass a custom
`DocumentValidator` to compose extra schemas. See
[schema validation](schema-validation.md) for that machinery and
[spec compliance](spec-compliance.md) for the per-section conformance ledger this
assertion underpins.

## Next / see also

- [Server](server.md) — `handle()` vs `dispatch()`, the two paths these utilities exercise.
- [Responses](responses.md) — the value objects the assertion wrappers render.
- [Operations](operations.md) — the `Target` and operation value objects the builders produce.
- [Schema validation](schema-validation.md) — `DocumentValidator` behind `assertJsonApiSpecCompliant()`.
- [Spec compliance](spec-compliance.md) — the 1.1 conformance ledger.
- [Getting started](getting-started.md) — a full request round-trip to test against.
