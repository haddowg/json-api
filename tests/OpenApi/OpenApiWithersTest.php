<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Components;
use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\Header;
use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\MediaType;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Operation;
use haddowg\JsonApi\OpenApi\Parameter;
use haddowg\JsonApi\OpenApi\ParameterLocation;
use haddowg\JsonApi\OpenApi\ParameterStyle;
use haddowg\JsonApi\OpenApi\PathItem;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApi\OpenApi\Reference;
use haddowg\JsonApi\OpenApi\RequestBody;
use haddowg\JsonApi\OpenApi\Response;
use haddowg\JsonApi\OpenApi\Responses;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Operation::class)]
#[CoversClass(PathItem::class)]
#[CoversClass(Response::class)]
#[CoversClass(Parameter::class)]
#[CoversClass(Info::class)]
#[CoversClass(OpenApi::class)]
#[CoversClass(Components::class)]
#[CoversClass(MediaType::class)]
#[CoversClass(RequestBody::class)]
#[CoversClass(Tag::class)]
#[CoversClass(Server::class)]
#[CoversClass(Schema::class)]
final class OpenApiWithersTest extends TestCase
{
    private const EXTENSIONS = ['x-feature' => 'agency', 'x-flags' => ['beta' => true]];

    /**
     * Each case: a fully populated VO carrying extensions, a wither applied to it,
     * the emitted member the wither changes, and that member's expected new value.
     *
     * @return iterable<string, array{Operation|PathItem|Response|Parameter|Info, \Closure, string, mixed}>
     */
    public static function withers(): iterable
    {
        $operation = self::operation();
        $docs = new ExternalDocumentation('https://example.com/other');
        yield 'operation responses' => [$operation, static fn(Operation $o): Operation => $o->withResponses((new Responses())->with('204', Response::noContent())), 'responses', ['204' => ['description' => 'No Content']]];
        yield 'operation response' => [$operation, static fn(Operation $o): Operation => $o->withResponse('429', new Response('Too Many Requests')), 'responses', ['200' => ['description' => 'OK'], '429' => ['description' => 'Too Many Requests']]];
        yield 'operation tags' => [$operation, static fn(Operation $o): Operation => $o->withTags(['other']), 'tags', ['other']];
        yield 'operation summary' => [$operation, static fn(Operation $o): Operation => $o->withSummary('Changed'), 'summary', 'Changed'];
        yield 'operation description' => [$operation, static fn(Operation $o): Operation => $o->withDescription('Changed'), 'description', 'Changed'];
        yield 'operation operationId' => [$operation, static fn(Operation $o): Operation => $o->withOperationId('changed'), 'operationId', 'changed'];
        yield 'operation externalDocs' => [$operation, static fn(Operation $o): Operation => $o->withExternalDocs($docs), 'externalDocs', ['url' => 'https://example.com/other']];
        yield 'operation parameters' => [$operation, static fn(Operation $o): Operation => $o->withParameters([Parameter::query('page')]), 'parameters', [['name' => 'page', 'in' => 'query']]];
        yield 'operation parameter replaced' => [$operation, static fn(Operation $o): Operation => $o->withParameter(Parameter::query('sort', description: 'Order')), 'parameters', [['name' => 'sort', 'in' => 'query', 'description' => 'Order'], ['$ref' => '#/components/parameters/Include']]];
        yield 'operation parameter appended' => [$operation, static fn(Operation $o): Operation => $o->withParameter(Parameter::query('page')), 'parameters', [['name' => 'sort', 'in' => 'query'], ['$ref' => '#/components/parameters/Include'], ['name' => 'page', 'in' => 'query']]];
        yield 'operation requestBody' => [$operation, static fn(Operation $o): Operation => $o->withRequestBody(Reference::to('requestBodies', 'Other')), 'requestBody', ['$ref' => '#/components/requestBodies/Other']];
        yield 'operation security' => [$operation, static fn(Operation $o): Operation => $o->withSecurity([]), 'security', []];
        yield 'operation deprecated' => [$operation, static fn(Operation $o): Operation => $o->withDeprecated(false), 'deprecated', false];

        $item = self::pathItem();
        yield 'path item operations' => [$item, static fn(PathItem $p): PathItem => $p->withOperations(['GET' => new Operation(new Responses())]), 'get', ['responses' => []]];
        yield 'path item operation' => [$item, static fn(PathItem $p): PathItem => $p->withOperation('get', new Operation(new Responses())), 'get', ['responses' => []]];
        yield 'path item summary' => [$item, static fn(PathItem $p): PathItem => $p->withSummary('Changed'), 'summary', 'Changed'];
        yield 'path item description' => [$item, static fn(PathItem $p): PathItem => $p->withDescription('Changed'), 'description', 'Changed'];
        yield 'path item parameters' => [$item, static fn(PathItem $p): PathItem => $p->withParameters([]), 'parameters', null];
        yield 'path item parameter' => [$item, static fn(PathItem $p): PathItem => $p->withParameter(Parameter::path('id', description: 'Changed')), 'parameters', [['name' => 'id', 'in' => 'path', 'description' => 'Changed', 'required' => true]]];

        $response = self::response();
        yield 'response description' => [$response, static fn(Response $r): Response => $r->withDescription('Changed'), 'description', 'Changed'];
        yield 'response headers' => [$response, static fn(Response $r): Response => $r->withHeaders([]), 'headers', null];
        yield 'response header' => [$response, static fn(Response $r): Response => $r->withHeader('Retry-After', new Header('Seconds')), 'headers', ['ETag' => ['description' => 'Version'], 'Retry-After' => ['description' => 'Seconds']]];
        yield 'response content' => [$response, static fn(Response $r): Response => $r->withContent([]), 'content', null];
        yield 'response media type' => [$response, static fn(Response $r): Response => $r->withMediaType(MediaType::JSON_API, new MediaType()), 'content', [MediaType::JSON_API => []]];

        $parameter = self::parameter();
        yield 'parameter name' => [$parameter, static fn(Parameter $p): Parameter => $p->withName('other'), 'name', 'other'];
        yield 'parameter in' => [$parameter, static fn(Parameter $p): Parameter => $p->withIn(ParameterLocation::Header), 'in', 'header'];
        yield 'parameter description' => [$parameter, static fn(Parameter $p): Parameter => $p->withDescription('Changed'), 'description', 'Changed'];
        yield 'parameter required' => [$parameter, static fn(Parameter $p): Parameter => $p->withRequired(false), 'required', false];
        yield 'parameter deprecated' => [$parameter, static fn(Parameter $p): Parameter => $p->withDeprecated(false), 'deprecated', false];
        yield 'parameter schema' => [$parameter, static fn(Parameter $p): Parameter => $p->withSchema(Schema::ofType('integer')), 'schema', ['type' => 'integer']];
        yield 'parameter style' => [$parameter, static fn(Parameter $p): Parameter => $p->withStyle(ParameterStyle::Form), 'style', 'form'];
        yield 'parameter explode' => [$parameter, static fn(Parameter $p): Parameter => $p->withExplode(false), 'explode', false];
        yield 'parameter example' => [$parameter, static fn(Parameter $p): Parameter => $p->withExample('b'), 'example', 'b'];
        yield 'parameter without example' => [$parameter, static fn(Parameter $p): Parameter => $p->withoutExample(), 'example', null];

        $info = self::info();
        yield 'info title' => [$info, static fn(Info $i): Info => $i->withTitle('Changed'), 'title', 'Changed'];
        yield 'info version' => [$info, static fn(Info $i): Info => $i->withVersion('2.0.0'), 'version', '2.0.0'];
        yield 'info summary' => [$info, static fn(Info $i): Info => $i->withSummary('Changed'), 'summary', 'Changed'];
        yield 'info description' => [$info, static fn(Info $i): Info => $i->withDescription('Changed'), 'description', 'Changed'];
        yield 'info termsOfService' => [$info, static fn(Info $i): Info => $i->withTermsOfService('https://example.com/tos2'), 'termsOfService', 'https://example.com/tos2'];
        yield 'info license' => [$info, static fn(Info $i): Info => $i->withLicense(null), 'license', null];
        yield 'info contact' => [$info, static fn(Info $i): Info => $i->withContact(null), 'contact', null];
    }

    #[Test]
    #[DataProvider('withers')]
    public function aWitherChangesOnlyItsMemberAndKeepsExtensions(Operation|PathItem|Response|Parameter|Info $vo, \Closure $wither, string $member, mixed $value): void
    {
        $changed = $wither($vo);
        if (!$changed instanceof $vo) {
            self::fail('The wither returned another type.');
        }

        $expected = $vo->toArray();
        if ($value === null) {
            unset($expected[$member]);
        } else {
            $expected[$member] = $value;
        }
        $actual = $changed->toArray();
        \ksort($expected);
        \ksort($actual);

        self::assertSame($expected, $actual);
        self::assertSame(self::EXTENSIONS, $changed->extensions());
        self::assertNotSame($vo, $changed);
    }

    #[Test]
    public function aDecoratorRebuildingAnOperationThroughWithersKeepsExtensionsSetEarlier(): void
    {
        $document = new OpenApi(
            new Info('API', '1.0.0'),
            new Components(),
            paths: (new Paths())->with('/agencies', (new PathItem())->withOperation('get', new Operation(
                (new Responses())->with('200', new Response('OK')),
                summary: 'List agencies',
            ))),
        );

        // One decorator tags the operation as feature-gated.
        $item = $document->paths?->items['/agencies'] ?? self::fail('path missing');
        $document = $document->withPath('/agencies', $item->withOperation('get', $item->operations['get']->withExtension('feature', 'agency')));

        // A later one documents rate limiting without knowing about the first.
        $item = $document->paths?->items['/agencies'] ?? self::fail('path missing');
        $operation = $item->operations['get']
            ->withResponse('429', new Response('Too Many Requests'))
            ->withDescription('Rate limited to 60 requests a minute.');
        $document = $document
            ->withPath('/agencies', $item->withOperation('get', $operation))
            ->withComponents($document->components->withResponse('TooManyRequests', new Response('Too Many Requests')));

        $get = $document->paths?->items['/agencies']->operations['get'] ?? self::fail('operation missing');
        self::assertSame('agency', $get->extension('feature'));
        self::assertSame(['x-feature' => 'agency'], $get->extensions());
        self::assertSame('List agencies', $get->summary);
        self::assertSame('Rate limited to 60 requests a minute.', $get->description);
        self::assertSame([200, 429], \array_keys($get->responses->responses));
        self::assertSame(['TooManyRequests'], \array_keys($document->components->responses));
    }

    #[Test]
    public function theOtherValueObjectsCarryWithersForTheirMembers(): void
    {
        $docs = new ExternalDocumentation('https://example.com/docs');
        $document = (new OpenApi(new Info('API', '1.0.0'), new Components()))
            ->withExternalDocs($docs)
            ->withJsonSchemaDialect('https://spec.openapis.org/oas/3.1/dialect/base');
        self::assertSame($docs, $document->externalDocs);
        self::assertSame('https://spec.openapis.org/oas/3.1/dialect/base', $document->jsonSchemaDialect);

        $components = (new Components())
            ->withParameter('Include', Parameter::query('include'))
            ->withRequestBody('Body', RequestBody::ofSchema(Schema::create()))
            ->withHeader('Retry-After', new Header('Seconds'));
        self::assertSame(['Include'], \array_keys($components->parameters));
        self::assertSame(['Body'], \array_keys($components->requestBodies));
        self::assertSame(['Retry-After'], \array_keys($components->headers));

        $media = MediaType::ofSchema(Schema::create())->withExample(['a' => 1])->withSchema(Reference::to('schemas', 'A'));
        self::assertSame(['schema' => ['$ref' => '#/components/schemas/A'], 'example' => ['a' => 1]], $media->toArray());

        $body = RequestBody::ofSchema(Schema::create())
            ->withDescription('Payload')
            ->withRequired(false)
            ->withMediaType('application/json', new MediaType());
        self::assertSame(['description' => 'Payload', 'content' => [MediaType::JSON_API => ['schema' => []], 'application/json' => []], 'required' => false], $body->toArray());
        self::assertSame(['content' => []], $body->withContent([])->withDescription(null)->withRequired(null)->toArray());

        $tag = (new Tag('albums'))->withDescription('Albums')->withExternalDocs($docs);
        self::assertSame(['name' => 'albums', 'description' => 'Albums', 'externalDocs' => ['url' => 'https://example.com/docs']], $tag->toArray());

        $server = (new Server('https://api.example.com'))->withDescription('Production')->withVariables([]);
        self::assertSame(['url' => 'https://api.example.com', 'description' => 'Production'], $server->toArray());

        $schema = Schema::ofType('string')->withExtension('profile', 'p');
        self::assertSame(['x-profile' => 'p'], $schema->extensions());
    }

    private static function operation(): Operation
    {
        return new Operation(
            (new Responses())->with('200', new Response('OK')),
            tags: ['albums'],
            summary: 'List',
            description: 'Lists albums.',
            operationId: 'listAlbums',
            externalDocs: new ExternalDocumentation('https://example.com/docs'),
            parameters: [Parameter::query('sort'), Reference::to('parameters', 'Include')],
            requestBody: RequestBody::ofSchema(Schema::create()),
            security: [SecurityRequirement::scheme('bearer')],
            deprecated: true,
            extensions: self::EXTENSIONS,
        );
    }

    private static function pathItem(): PathItem
    {
        return new PathItem(
            ['get' => new Operation((new Responses())->with('200', new Response('OK')))],
            'Albums',
            'The album collection.',
            [Parameter::path('id')],
            self::EXTENSIONS,
        );
    }

    private static function response(): Response
    {
        return new Response(
            'OK',
            ['ETag' => new Header('Version')],
            [MediaType::JSON_API => MediaType::ofSchema(Schema::create())],
            self::EXTENSIONS,
        );
    }

    private static function parameter(): Parameter
    {
        return (new Parameter(
            'filter[name]',
            ParameterLocation::Query,
            'By name',
            true,
            true,
            Schema::ofType('string'),
            ParameterStyle::DeepObject,
            true,
            self::EXTENSIONS,
        ))->withExample('a');
    }

    private static function info(): Info
    {
        return new Info(
            'API',
            '1.0.0',
            'Summary',
            'Description',
            'https://example.com/tos',
            new Contact('Team'),
            new License('MIT'),
            self::EXTENSIONS,
        );
    }
}
