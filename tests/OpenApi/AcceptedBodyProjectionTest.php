<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Tests\OpenApi\Fixture\ArticleImportRow;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Raw\Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiProjector::class)]
#[CoversClass(AcceptedBody::class)]
final class AcceptedBodyProjectionTest extends TestCase
{
    #[Test]
    public function eachAcceptedBodyIsOneContentEntry(): void
    {
        $document = $this->project(ActionInputMode::Raw, [
            new AcceptedBody('text/csv; charset=utf-8', schema: ['type' => 'string']),
            new AcceptedBody('image/*', binary: true),
            new AcceptedBody('multipart/form-data'),
            new AcceptedBody('application/octet-stream'),
            new AcceptedBody('application/json', schema: ArticleImportRow::class),
        ]);

        $body = self::at($document, 'paths', '/reports/-actions/import', 'post', 'requestBody');
        self::assertSame([
            'description' => 'The request body, in one of the media types the action accepts.',
            'content' => [
                'text/csv' => ['schema' => ['type' => 'string']],
                'image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                'multipart/form-data' => ['schema' => ['type' => 'object']],
                'application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/ArticleImportRow']],
            ],
            'required' => false,
        ], $body);
        self::assertSame(ArticleImportRow::jsonSchema(), self::at($document, 'components', 'schemas', 'ArticleImportRow'));
        self::assertSame(['contract' => GeneratorContract::ACCEPTED_BODIES], self::at($document, 'info', 'x-generator'));
        self::assertNotNull(self::at($document, 'paths', '/reports/-actions/import', 'post', 'responses', '415'));
    }

    #[Test]
    public function aSchemalessEntryDefaultsByMediaTypeFamily(): void
    {
        $document = $this->project(ActionInputMode::Raw, [
            new AcceptedBody('text/csv'),
            new AcceptedBody('text/*'),
            new AcceptedBody('application/json'),
            new AcceptedBody('application/geo+json'),
            new AcceptedBody('multipart/form-data'),
            new AcceptedBody('application/x-www-form-urlencoded'),
            new AcceptedBody('image/png'),
            new AcceptedBody('application/pdf'),
            new AcceptedBody('application/octet-stream'),
            new AcceptedBody('*/*'),
        ]);

        $content = self::at($document, 'paths', '/reports/-actions/import', 'post', 'requestBody', 'content');
        self::assertSame([
            'text/csv' => ['schema' => ['type' => 'string']],
            'text/*' => ['schema' => ['type' => 'string']],
            'application/json' => ['schema' => []],
            'application/geo+json' => ['schema' => []],
            'multipart/form-data' => ['schema' => ['type' => 'object']],
            'application/x-www-form-urlencoded' => ['schema' => ['type' => 'object']],
            'image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            'application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            'application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            '*/*' => ['schema' => ['type' => 'string', 'format' => 'binary']],
        ], $content);
    }

    #[Test]
    public function anAnyJsonDefaultEncodesAsAnEmptyObject(): void
    {
        $types = [new FakeTypeMetadata(
            type: 'reports',
            uriType: 'reports',
            hasFields: false,
            actions: [new FakeActionMetadata('import', ['POST'], ActionScope::Collection, ActionInputMode::Raw, accepts: [new AcceptedBody('application/json')])],
        )];
        $json = \json_encode((new OpenApiProjector())->project(new FakeServerMetadata('Accepts', '1', $types)), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"application\/json":{"schema":{}}', $json);
    }

    #[Test]
    public function anExplicitSchemaOrBinaryOverridesTheDefault(): void
    {
        $document = $this->project(ActionInputMode::Raw, [
            new AcceptedBody('text/csv', binary: true),
            new AcceptedBody('application/json', schema: ['type' => 'array']),
            new AcceptedBody('image/svg+xml', schema: ['type' => 'string']),
        ]);

        $content = self::at($document, 'paths', '/reports/-actions/import', 'post', 'requestBody', 'content');
        self::assertSame([
            'text/csv' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            'application/json' => ['schema' => ['type' => 'array']],
            'image/svg+xml' => ['schema' => ['type' => 'string']],
        ], $content);
    }

    #[Test]
    public function aJsonApiEntryCarriesTheActionsRequestDocument(): void
    {
        $document = $this->project(ActionInputMode::Document, [
            new AcceptedBody('application/vnd.api+json'),
            new AcceptedBody('text/csv'),
        ]);

        $body = self::at($document, 'paths', '/reports/-actions/import', 'post', 'requestBody');
        self::assertSame(['type' => 'object'], self::at($body, 'content', 'application/vnd.api+json', 'schema'));
        self::assertTrue(self::at($body, 'required'));
    }

    #[Test]
    public function noAcceptedBodiesKeepTheBaselineContract(): void
    {
        $document = $this->project(ActionInputMode::Raw, []);

        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($document, 'info', 'x-generator'));
    }

    #[Test]
    public function noAcceptedBodiesKeepsTheInputModeProjection(): void
    {
        $document = $this->project(ActionInputMode::Raw, []);

        self::assertSame(
            ['application/octet-stream'],
            \array_keys(self::arr(self::at($document, 'paths', '/reports/-actions/import', 'post', 'requestBody', 'content'))),
        );
        self::assertNull(self::at($document, 'paths', '/reports/-actions/import', 'post', 'responses', '415'));
    }

    #[Test]
    public function aSchemaClassWhoseNameIsTakenIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Error"');
        $this->project(ActionInputMode::Raw, [new AcceptedBody('application/json', schema: Error::class)]);
    }

    #[Test]
    public function anInvalidDeclarationIsRefused(): void
    {
        foreach ([
            static fn(): AcceptedBody => new AcceptedBody('csv'),
            static fn(): AcceptedBody => new AcceptedBody('*/json'),
            static fn(): AcceptedBody => new AcceptedBody('image/png', schema: ['type' => 'string'], binary: true),
            static fn(): AcceptedBody => new AcceptedBody('application/vnd.api+json', binary: true),
        ] as $declare) {
            try {
                $declare();
                self::fail('The declaration should be refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<AcceptedBody> $accepts
     *
     * @return array<string, mixed>
     */
    private function project(ActionInputMode $mode, array $accepts): array
    {
        $types = [new FakeTypeMetadata(
            type: 'reports',
            uriType: 'reports',
            hasFields: false,
            actions: [new FakeActionMetadata('import', ['POST'], ActionScope::Collection, $mode, accepts: $accepts)],
        )];

        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project(new FakeServerMetadata('Accepts', '1', $types))->toArray();
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
