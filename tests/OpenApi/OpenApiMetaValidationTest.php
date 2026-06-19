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
use haddowg\JsonApi\OpenApi\PathItem;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApi\OpenApi\Reference;
use haddowg\JsonApi\OpenApi\RequestBody;
use haddowg\JsonApi\OpenApi\Response;
use haddowg\JsonApi\OpenApi\Responses;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\ServerVariable;
use haddowg\JsonApi\OpenApi\Tag;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Meta-validates documents assembled from the OpenAPI 3.1 value-object model
 * against the **official OAS 3.1 meta-schema**, the spec §10 acceptance criterion
 * ("every emitted document validates against the meta-schema").
 *
 * **Vendoring.** `opis/json-schema` ships the 2020-12 *parser* but no meta-schema
 * JSON, so the OAS 3.1 schema documents are vendored under `Fixture/oas-3.1/`
 * (`schema.json` + the `dialect.json` / `meta/base.json` it references) and
 * registered by their canonical `$id`, alongside the **already-vendored 2020-12
 * meta-schema** (`Fixture/meta-schema/`, reused from Slice 1) — the OAS schema
 * declares `$schema: …/2020-12/schema`, so the 2020-12 documents must resolve too.
 *
 * **Two documented local adaptations of the vendored schema** (both work around
 * `opis/json-schema` 2.6 annotation/resolution gaps, not spec defects; the pristine
 * upstream document is kept verbatim at `schema.upstream.json` for provenance/
 * diffing, and this test asserts both acceptance *and* rejection to prove the
 * adaptations preserve the meta-schema's real constraints):
 *
 *  1. **Dynamic-ref over-resolution.** The official schema validates a Schema Object
 *     slot (`components.schemas.*`, a parameter / media-type / header `schema`) via
 *     `{"$dynamicRef": "#meta"}`, whose only `$dynamicAnchor: "meta"` in scope is the
 *     deliberately-permissive `$defs/schema` placeholder (`{"type": ["object",
 *     "boolean"]}`). opis 2.6 resolves that dynamic reference to the **document
 *     root** instead of `$defs/schema`, so every component schema would be (wrongly)
 *     required to be a full OpenAPI document. Because there is exactly one `meta`
 *     dynamic anchor, dynamic and static resolution are equivalent, so the four
 *     `{"$dynamicRef": "#meta"}` slots are rewritten to the equivalent
 *     `{"$ref": "#/$defs/schema"}`. The deep JSON-Schema-2020-12 validity of the
 *     Schema Objects themselves is covered separately by
 *     {@see SchemaProjectorMetaValidationTest}.
 *  2. **`unevaluatedProperties` under heavy `if/then` nesting.** The Parameter and
 *     Header Object defs (the parameter-shaped family) close themselves with
 *     `unevaluatedProperties: false` over a deeply nested `dependentSchemas` →
 *     `allOf` → `if/then/else` style chain. opis 2.6 does not fully propagate the
 *     conditional branches' property annotations, so it spuriously flags even a
 *     fully spec-compliant parameter/header (`style`/`explode`/`allowReserved`) as
 *     carrying unevaluated properties. `unevaluatedProperties` is relaxed to `true`
 *     on the `parameter` and `header` defs only; their own `properties`/`required`
 *     (incl. the conditional `path` → `required: true`) remain enforced. The other
 *     conditional defs (`securityScheme`, `mediaType`) are left untouched — their
 *     `allOf` conditionals do not trip opis (this test exercises both).
 */
#[CoversClass(OpenApi::class)]
#[Group('spec:document-structure')]
final class OpenApiMetaValidationTest extends TestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    /**
     * A validator with the vendored OAS 3.1 + JSON Schema 2020-12 meta-schema
     * documents registered by their canonical `$id`.
     */
    private function metaValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $register = function (string $base, array $documents) use ($resolver): void {
            foreach ($documents as $document) {
                $raw = \file_get_contents($base . $document);
                self::assertIsString($raw);
                $decoded = \json_decode($raw);
                self::assertInstanceOf(\stdClass::class, $decoded);
                $id = $decoded->{'$id'} ?? null;
                self::assertIsString($id);
                $resolver->registerRaw($decoded, $id);
            }
        };

        // The OAS 3.1 schema declares $schema: 2020-12, so both dialects resolve.
        $register(__DIR__ . '/Fixture/meta-schema/', [
            'schema.json',
            'meta/core.json',
            'meta/applicator.json',
            'meta/unevaluated.json',
            'meta/validation.json',
            'meta/meta-data.json',
            'meta/format-annotation.json',
            'meta/content.json',
        ]);
        $register(__DIR__ . '/Fixture/oas-3.1/', [
            'schema.json',
            'dialect.json',
            'meta/base.json',
        ]);

        return $validator;
    }

    private function assertValidOpenApiDocument(OpenApi $document): void
    {
        $result = $this->metaValidator()->validate($document->toJson(), self::OAS_SCHEMA_ID);

        self::assertTrue(
            $result->isValid(),
            'Assembled document is not a valid OpenAPI 3.1 document: ' . $document->toJsonString(true),
        );
    }

    private function assertInvalidDocument(\stdClass $document): void
    {
        self::assertFalse(
            $this->metaValidator()->validate($document, self::OAS_SCHEMA_ID)->isValid(),
        );
    }

    #[Test]
    public function aMinimalSkeletonDocumentValidates(): void
    {
        // The spec §9 Slice-2 witness: a path-less skeleton with one component schema.
        // (OAS 3.1 requires at least one of paths/components/webhooks; the model
        // always emits components, so the skeleton is conformant without paths.)
        $document = new OpenApi(
            info: new Info('Minimal API', '1.0.0'),
            components: (new Components())->withSchema('Article', Schema::ofType('object')),
        );

        self::assertArrayNotHasKey('paths', $document->toArray());
        $this->assertValidOpenApiDocument($document);
    }

    #[Test]
    public function aRichSkeletonDocumentValidates(): void
    {
        $document = new OpenApi(
            info: (new Info('Music API', '1.2.0'))
                ->withDescription('A JSON:API surface')
                ->withContact(new Contact('Greg', 'https://example.com', 'g@example.com'))
                ->withLicense(new License('MIT', identifier: 'MIT')),
            components: (new Components())
                ->withSchema('Article', Schema::ofType('object')
                    ->withProperty('type', Schema::ofType('string')->withConst('articles'))
                    ->withProperty('id', Schema::ofType('string'))
                    ->withRequired(['type']))
                ->withSchema('Status', Schema::ofType('string')
                    ->withEnum(['draft', 'published'])
                    ->withExtension('enum-varnames', ['Draft', 'Published']))
                ->withSecurityScheme('bearer', SecurityScheme::bearer('JWT', 'Bearer token'))
                ->withSecurityScheme('oauth', SecurityScheme::oauth2(new OAuthFlows(
                    authorizationCode: new OAuthFlow(['read' => 'Read access'], 'https://auth.example.com/authorize', 'https://auth.example.com/token'),
                ))),
            servers: [new Server('https://api.example.com', 'Production')],
            security: [SecurityRequirement::scheme('bearer')],
            tags: [new Tag('Articles', 'Blog articles', new ExternalDocumentation('https://docs.example.com'))],
            externalDocs: new ExternalDocumentation('https://example.com', 'Project home'),
        );

        $this->assertValidOpenApiDocument($document);
    }

    #[Test]
    public function aServerWithVariablesValidates(): void
    {
        $document = new OpenApi(
            info: new Info('Versioned API', '1.0.0'),
            components: (new Components())->withSchema('Article', Schema::ofType('object')),
            // A plain URL with a template variable that is a valid uri-reference.
            servers: [new Server('https://api.example.com/v1', 'Production', [
                'tier' => new ServerVariable('public', ['public', 'internal'], 'API tier'),
            ])],
        );

        $this->assertValidOpenApiDocument($document);
    }

    #[Test]
    public function aPathBearingDocumentValidates(): void
    {
        // Forward-proofs the path-level VOs (populated for real in Slice 3): a full
        // CRUD operation with parameters, a request body, and enumerated responses
        // referencing component schemas already validates against OAS 3.1.
        $createArticle = new Operation(
            responses: (new Responses())
                ->with('201', new Response(
                    'Created',
                    headers: ['Location' => new Header('URL of the created resource', schema: Schema::ofType('string')->withFormat('uri'))],
                    content: [MediaType::JSON_API => MediaType::ofSchema(Reference::to('schemas', 'Article'))],
                ))
                ->with('422', Response::ofSchema('Unprocessable Entity', Reference::to('schemas', 'ErrorDocument')))
                ->with('204', Response::noContent()),
            tags: ['Articles'],
            summary: 'Create an article',
            operationId: 'createArticle',
            parameters: [
                Parameter::query('include', Schema::ofType('string'), 'Relationship paths to include'),
            ],
            requestBody: RequestBody::ofSchema(Reference::to('schemas', 'ArticleCreate')),
            security: [SecurityRequirement::scheme('bearer')],
        );

        $document = new OpenApi(
            info: new Info('Music API', '1.0.0'),
            components: (new Components())
                ->withSchema('Article', Schema::ofType('object'))
                ->withSchema('ArticleCreate', Schema::ofType('object'))
                ->withSchema('ErrorDocument', Schema::ofType('object'))
                ->withSecurityScheme('bearer', SecurityScheme::bearer('JWT')),
            paths: (new Paths())->with('/articles/{id}', (new PathItem())->withOperation('post', $createArticle)),
            security: [SecurityRequirement::scheme('bearer')],
            tags: [new Tag('Articles')],
        );

        self::assertArrayHasKey('paths', $document->toArray());
        $this->assertValidOpenApiDocument($document);
    }

    #[Test]
    public function aDocumentMissingPathsComponentsAndWebhooksIsRejected(): void
    {
        // OAS 3.1 requires at least one of paths/components/webhooks.
        $bad = new \stdClass();
        $bad->openapi = '3.1.0';
        $bad->info = (object) ['title' => 'x', 'version' => '1'];

        $this->assertInvalidDocument($bad);
    }

    #[Test]
    public function aWrongOpenApiVersionIsRejected(): void
    {
        $bad = new \stdClass();
        $bad->openapi = '2.0';
        $bad->info = (object) ['title' => 'x', 'version' => '1'];
        $bad->components = new \stdClass();

        $this->assertInvalidDocument($bad);
    }

    #[Test]
    public function anApiKeySchemeMissingNameAndInIsRejected(): void
    {
        // Proves the adaptation preserves the meta-schema's conditional
        // security-scheme constraints (an apiKey requires name + in).
        $components = new \stdClass();
        $scheme = new \stdClass();
        $scheme->type = 'apiKey';
        $components->securitySchemes = (object) ['k' => $scheme];

        $bad = new \stdClass();
        $bad->openapi = '3.1.0';
        $bad->info = (object) ['title' => 'x', 'version' => '1'];
        $bad->components = $components;

        $this->assertInvalidDocument($bad);
    }
}
