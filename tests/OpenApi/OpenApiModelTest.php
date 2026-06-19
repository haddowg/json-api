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
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Operation;
use haddowg\JsonApi\OpenApi\Parameter;
use haddowg\JsonApi\OpenApi\ParameterLocation;
use haddowg\JsonApi\OpenApi\PathItem;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApi\OpenApi\Reference;
use haddowg\JsonApi\OpenApi\RequestBody;
use haddowg\JsonApi\OpenApi\Response;
use haddowg\JsonApi\OpenApi\Responses;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\SecuritySchemeType;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\ServerVariable;
use haddowg\JsonApi\OpenApi\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip tests for the OpenAPI 3.1 value-object model: each VO emits the
 * expected **canonical OAS 3.1** array (absent/empty members omitted), and the
 * JSON-faithful object graph distinguishes an empty *object* (`{}`) from an empty
 * *list* (`[]`) where the spec requires it.
 */
#[CoversClass(OpenApi::class)]
#[CoversClass(Info::class)]
#[CoversClass(Contact::class)]
#[CoversClass(License::class)]
#[CoversClass(Server::class)]
#[CoversClass(ServerVariable::class)]
#[CoversClass(Components::class)]
#[CoversClass(Tag::class)]
#[CoversClass(ExternalDocumentation::class)]
#[CoversClass(SecurityScheme::class)]
#[CoversClass(OAuthFlows::class)]
#[CoversClass(OAuthFlow::class)]
#[CoversClass(SecurityRequirement::class)]
#[CoversClass(Reference::class)]
#[CoversClass(Paths::class)]
#[CoversClass(PathItem::class)]
#[CoversClass(Operation::class)]
#[CoversClass(Parameter::class)]
#[CoversClass(RequestBody::class)]
#[CoversClass(Response::class)]
#[CoversClass(Responses::class)]
#[CoversClass(MediaType::class)]
#[CoversClass(Header::class)]
#[Group('spec:document-structure')]
final class OpenApiModelTest extends TestCase
{
    #[Test]
    public function infoOmitsAbsentMembersAndEmitsTitleAndVersion(): void
    {
        self::assertSame(
            ['title' => 'My API', 'version' => '1.0.0'],
            (new Info('My API', '1.0.0'))->toArray(),
        );
    }

    #[Test]
    public function infoEmitsContactLicenseAndDescription(): void
    {
        $info = (new Info('My API', '2.0.0'))
            ->withDescription('A description')
            ->withContact(new Contact('Greg', 'https://x', 'g@x.com'))
            ->withLicense(new License('MIT', identifier: 'MIT'));

        self::assertSame([
            'title' => 'My API',
            'description' => 'A description',
            'contact' => ['name' => 'Greg', 'url' => 'https://x', 'email' => 'g@x.com'],
            'license' => ['name' => 'MIT', 'identifier' => 'MIT'],
            'version' => '2.0.0',
        ], $info->toArray());
    }

    #[Test]
    public function contactOmitsAllAbsentMembers(): void
    {
        self::assertSame([], (new Contact())->toArray());
        self::assertSame(['email' => 'a@b.com'], (new Contact(email: 'a@b.com'))->toArray());
    }

    #[Test]
    public function licenseEmitsNamePlusIdentifierOrUrl(): void
    {
        self::assertSame(['name' => 'MIT', 'identifier' => 'MIT'], (new License('MIT', identifier: 'MIT'))->toArray());
        self::assertSame(['name' => 'Apache', 'url' => 'https://x'], (new License('Apache', url: 'https://x'))->toArray());
    }

    #[Test]
    public function serverEmitsUrlAndVariables(): void
    {
        $server = new Server(
            'https://api.example.com/{version}',
            'Production',
            ['version' => new ServerVariable('v1', ['v1', 'v2'], 'API version')],
        );

        self::assertSame([
            'url' => 'https://api.example.com/{version}',
            'description' => 'Production',
            'variables' => [
                'version' => ['enum' => ['v1', 'v2'], 'default' => 'v1', 'description' => 'API version'],
            ],
        ], $server->toArray());
    }

    #[Test]
    public function serverVariableOmitsEmptyEnum(): void
    {
        self::assertSame(['default' => 'v1'], (new ServerVariable('v1'))->toArray());
    }

    #[Test]
    public function tagEmitsNameDescriptionAndExternalDocs(): void
    {
        $tag = new Tag('Articles', 'Blog articles', new ExternalDocumentation('https://docs', 'More'));

        self::assertSame([
            'name' => 'Articles',
            'description' => 'Blog articles',
            'externalDocs' => ['description' => 'More', 'url' => 'https://docs'],
        ], $tag->toArray());
    }

    #[Test]
    public function externalDocumentationEmitsUrlLastAfterDescription(): void
    {
        self::assertSame(
            ['description' => 'd', 'url' => 'https://x'],
            (new ExternalDocumentation('https://x', 'd'))->toArray(),
        );
    }

    #[Test]
    public function bearerSecuritySchemeEmitsHttpScheme(): void
    {
        $scheme = SecurityScheme::bearer('JWT', 'JWT auth');

        self::assertSame(SecuritySchemeType::Http, $scheme->type);
        self::assertSame([
            'type' => 'http',
            'description' => 'JWT auth',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ], $scheme->toArray());
    }

    #[Test]
    public function apiKeySecuritySchemeEmitsNameAndIn(): void
    {
        self::assertSame(
            ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
            SecurityScheme::apiKey('X-Api-Key', 'header')->toArray(),
        );
    }

    #[Test]
    public function oauth2SchemeEmitsFlowsWithScopesObject(): void
    {
        $scheme = SecurityScheme::oauth2(new OAuthFlows(
            authorizationCode: new OAuthFlow(['read' => 'Read access'], 'https://x/auth', 'https://x/token'),
        ));

        self::assertSame([
            'type' => 'oauth2',
            'flows' => [
                'authorizationCode' => [
                    'authorizationUrl' => 'https://x/auth',
                    'tokenUrl' => 'https://x/token',
                    'scopes' => ['read' => 'Read access'],
                ],
            ],
        ], $scheme->toArray());
    }

    #[Test]
    public function oauthFlowAlwaysEmitsScopesEvenWhenEmpty(): void
    {
        $flow = new OAuthFlow([], tokenUrl: 'https://x/token');

        self::assertSame(['tokenUrl' => 'https://x/token', 'scopes' => []], $flow->toArray());

        // The JSON form renders empty scopes as an object, not an array.
        $json = \json_encode($flow->toJson(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertSame('{"tokenUrl":"https://x/token","scopes":{}}', $json);
    }

    #[Test]
    public function securityRequirementRendersEmptyScopesAsAList(): void
    {
        $requirement = SecurityRequirement::scheme('bearer');

        self::assertSame(['bearer' => []], $requirement->toArray());

        // The empty scope list is the one spec-correct empty *array* in the model.
        $json = \json_encode($requirement->toJson(), \JSON_THROW_ON_ERROR);
        self::assertSame('{"bearer":[]}', $json);
    }

    #[Test]
    public function referenceSerializesToARefObject(): void
    {
        self::assertSame(['$ref' => '#/components/schemas/Article'], Reference::to('schemas', 'Article')->toArray());

        $json = \json_encode(Reference::to('schemas', 'Article')->toJson(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertSame('{"$ref":"#/components/schemas/Article"}', $json);
    }

    #[Test]
    public function parameterEmitsNameInAndSchema(): void
    {
        $param = Parameter::query('include', Schema::ofType('string'), 'Relationship paths to include');

        self::assertSame([
            'name' => 'include',
            'in' => 'query',
            'description' => 'Relationship paths to include',
            'schema' => ['type' => 'string'],
        ], $param->toArray());
        self::assertSame(ParameterLocation::Query, $param->in);
    }

    #[Test]
    public function pathParameterIsRequired(): void
    {
        self::assertSame([
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ], Parameter::path('id', Schema::ofType('string'))->toArray());
    }

    #[Test]
    public function requestBodyEmitsContentMapAndRequired(): void
    {
        $body = RequestBody::ofSchema(Reference::to('schemas', 'ArticleCreate'));

        self::assertSame([
            'content' => [
                'application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/ArticleCreate']],
            ],
            'required' => true,
        ], $body->toArray());
    }

    #[Test]
    public function noContentResponseOmitsContent(): void
    {
        self::assertSame(['description' => 'No Content'], Response::noContent()->toArray());
    }

    #[Test]
    public function responseEmitsHeadersAndContent(): void
    {
        $response = new Response(
            'Created',
            headers: ['Location' => new Header('URL of the created resource', schema: Schema::ofType('string')->withFormat('uri'))],
            content: ['application/vnd.api+json' => MediaType::ofSchema(Reference::to('schemas', 'Article'))],
        );

        self::assertSame([
            'description' => 'Created',
            'headers' => [
                'Location' => ['description' => 'URL of the created resource', 'schema' => ['type' => 'string', 'format' => 'uri']],
            ],
            'content' => [
                'application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/Article']],
            ],
        ], $response->toArray());
    }

    #[Test]
    public function responsesIsAStatusKeyedMap(): void
    {
        $responses = (new Responses())
            ->with('200', Response::ofSchema('OK', Schema::ofType('object')))
            ->with('204', Response::noContent());

        self::assertSame([
            '200' => ['description' => 'OK', 'content' => ['application/vnd.api+json' => ['schema' => ['type' => 'object']]]],
            '204' => ['description' => 'No Content'],
        ], $responses->toArray());
    }

    #[Test]
    public function operationCarriesTagsParametersRequestBodyAndResponses(): void
    {
        $operation = new Operation(
            responses: (new Responses())->with('201', Response::noContent()),
            tags: ['Articles'],
            summary: 'Create an article',
            operationId: 'createArticle',
            parameters: [Parameter::query('include', Schema::ofType('string'))],
            requestBody: RequestBody::ofSchema(Reference::to('schemas', 'ArticleCreate')),
            security: [SecurityRequirement::scheme('bearer')],
        );

        self::assertSame([
            'tags' => ['Articles'],
            'summary' => 'Create an article',
            'operationId' => 'createArticle',
            'parameters' => [
                ['name' => 'include', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
            'requestBody' => [
                'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/ArticleCreate']]],
                'required' => true,
            ],
            'responses' => ['201' => ['description' => 'No Content']],
            'security' => [['bearer' => []]],
        ], $operation->toArray());
    }

    #[Test]
    public function pathItemEmitsOperationsInMethodOrder(): void
    {
        $get = new Operation(responses: (new Responses())->with('200', Response::ofSchema('OK', Schema::ofType('object'))));
        $post = new Operation(responses: (new Responses())->with('201', Response::noContent()));

        // Added post-then-get, but emitted get-then-post (canonical method order).
        $item = (new PathItem())->withOperation('post', $post)->withOperation('get', $get);

        self::assertSame(['get', 'post'], \array_keys($item->toArray()));
    }

    #[Test]
    public function pathsIsEmptyByDefaultAndCarriesPathItems(): void
    {
        self::assertTrue((new Paths())->isEmpty());

        $paths = (new Paths())->with('/articles', new PathItem());
        self::assertFalse($paths->isEmpty());
        self::assertSame(['/articles'], \array_keys($paths->toArray()));
    }

    #[Test]
    public function componentsOmitEmptySectionsAndCarrySchemas(): void
    {
        self::assertTrue((new Components())->isEmpty());

        $components = (new Components())
            ->withSchema('Article', Schema::ofType('object'))
            ->withSecurityScheme('bearer', SecurityScheme::bearer());

        self::assertSame([
            'schemas' => ['Article' => ['type' => 'object']],
            'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
        ], $components->toArray());
        self::assertFalse($components->isEmpty());
    }

    #[Test]
    public function emptyComponentsSectionsRenderAsObjectsInJson(): void
    {
        // An empty Components serializes to `{}`, never `[]` — it is a required-ish
        // root member whose JSON form must be a JSON object.
        $json = \json_encode((new Components())->toJson(), \JSON_THROW_ON_ERROR);
        self::assertSame('{}', $json);
    }

    #[Test]
    public function openApiRootPinsVersionAndEmitsComponents(): void
    {
        $doc = new OpenApi(
            info: new Info('My API', '1.0.0'),
            components: (new Components())->withSchema('Article', Schema::ofType('object')),
        );

        self::assertSame([
            'openapi' => '3.1.0',
            'info' => ['title' => 'My API', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Article' => ['type' => 'object']]],
        ], $doc->toArray());
    }

    #[Test]
    public function openApiRootOmitsEmptyPathsButKeepsComponents(): void
    {
        $doc = new OpenApi(
            info: new Info('My API', '1.0.0'),
            components: (new Components())->withSchema('Article', Schema::ofType('object')),
            paths: new Paths(),
        );

        self::assertArrayNotHasKey('paths', $doc->toArray());
        self::assertArrayHasKey('components', $doc->toArray());
    }

    #[Test]
    public function openApiToJsonStringEmitsValidJson(): void
    {
        $doc = new OpenApi(
            info: new Info('My API', '1.0.0'),
            components: (new Components())->withSchema('Article', Schema::ofType('object')),
        );

        $decoded = \json_decode($doc->toJsonString(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi']);
        self::assertIsArray($decoded['info']);
        self::assertSame('My API', $decoded['info']['title']);
    }

    #[Test]
    public function jsonSerializeMatchesToJson(): void
    {
        $doc = new OpenApi(
            info: new Info('My API', '1.0.0'),
            components: (new Components())->withSchema('Article', Schema::ofType('object')),
        );

        self::assertEquals($doc->toJson(), $doc->jsonSerialize());
    }
}
