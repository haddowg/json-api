<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every member of a pivot relation's response linkage renders `meta.pivot`, `{}` when the
 * request hides every pivot field, so each response linkage identifier requires `meta` and
 * its `pivot`. The identifiers a client sends may leave both out.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(RelationFacts::class)]
final class RequiredResponsePivotProjectionTest extends TestCase
{
    private const ROOT = 'https://witness.example/openapi.json';

    #[Test]
    public function responseLinkageRequiresMetaAndItsPivot(): void
    {
        $schemas = $this->arrAt($this->document(self::tags()), 'components', 'schemas');

        $narrowing = [
            'type' => 'object',
            'properties' => ['meta' => [
                'type' => 'object',
                'properties' => ['pivot' => ['$ref' => '#/components/schemas/ArticlesTagsPivot']],
                'required' => ['pivot'],
            ]],
            'required' => ['meta'],
        ];
        foreach (['ArticlesTagsRelationship', 'ArticlesTagsRelationshipDocument'] as $component) {
            self::assertSame($narrowing, $this->arrAt($schemas, $component, 'properties', 'data', 'items', 'allOf', '1'), $component);
        }
    }

    #[Test]
    public function requestLinkageLeavesMetaAndItsPivotOptional(): void
    {
        $schemas = $this->arrAt($this->document(self::tags()), 'components', 'schemas');

        foreach (['ArticlesTagsWriteRelationship', 'ArticlesTagsRelationshipRequest'] as $component) {
            self::assertSame(
                ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'properties' => ['pivot' => ['$ref' => '#/components/schemas/ArticlesTagsWritePivot']]]]],
                $this->arrAt($schemas, $component, 'properties', 'data', 'items', 'allOf', '1'),
                $component,
            );
        }
    }

    #[Test]
    public function declaredIdentifierMetaRequiresThePivotBesideItsOwnMembers(): void
    {
        $schemas = $this->arrAt($this->document(new FakeRelationMetadata(
            'tags',
            ['tags'],
            true,
            pivotFields: [Integer::make('position')->build()],
            identifierMetaFields: [Str::make('addedVia')->build()],
        )), 'components', 'schemas');

        self::assertSame(['addedVia', 'pivot'], $this->arrAt($schemas, 'ArticlesTagsIdentifierMeta', 'required'));
        self::assertSame(
            ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/ArticlesTagsIdentifierMeta']], 'required' => ['meta']],
            $this->arrAt($schemas, 'ArticlesTagsRelationship', 'properties', 'data', 'items', 'allOf', '1'),
        );
    }

    #[Test]
    public function declaredIdentifierMetaWithoutAPivotLeavesMetaOptional(): void
    {
        $schemas = $this->arrAt($this->document(new FakeRelationMetadata('tags', ['tags'], true, identifierMetaFields: [Str::make('addedVia')->build()])), 'components', 'schemas');

        self::assertSame(['addedVia'], $this->arrAt($schemas, 'ArticlesTagsIdentifierMeta', 'required'));
        self::assertSame(
            ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/ArticlesTagsIdentifierMeta']]],
            $this->arrAt($schemas, 'ArticlesTagsRelationship', 'properties', 'data', 'items', 'allOf', '1'),
        );
    }

    #[Test]
    public function aPivotRelationshipObjectExampleCarriesItsPivotAndValidates(): void
    {
        $relation = new FakeRelationMetadata(
            'tags',
            ['tags'],
            true,
            pivotFields: [Integer::make('position')->required()->example(2)->build(), Str::make('note')->readOnly()->example('pinned')->build()],
            identifierMetaFields: [Str::make('addedVia')->example('editor')->build()],
        );
        $document = \json_decode((new OpenApiProjector())->project($this->server($relation))->toJsonString(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        $component = $document->components->schemas->ArticlesTagsRelationship;
        self::assertEquals(
            [(object) ['data' => [(object) ['type' => 'tags', 'id' => 'tag-1', 'meta' => (object) ['addedVia' => 'editor', 'pivot' => (object) ['position' => 2, 'note' => 'pinned']]]]]],
            $component->examples,
        );

        $validator = new Validator();
        $validator->resolver()?->registerRaw((object) ['components' => $document->components], self::ROOT);
        $result = $validator->validate($component->examples[0], (object) ['$ref' => self::ROOT . '#/components/schemas/ArticlesTagsRelationship']);
        self::assertTrue($result->isValid(), $result->error()?->message() ?? '');
    }

    #[Test]
    public function aPivotRelationshipObjectHasNoExampleWhenAPivotValueHasNone(): void
    {
        $schemas = $this->arrAt($this->document(self::tags()), 'components', 'schemas');

        self::assertArrayNotHasKey('examples', $this->arrAt($schemas, 'ArticlesTagsRelationship'));
        self::assertSame([['data' => [['type' => 'tags', 'id' => 'tag-1']]]], $this->arrAt($schemas, 'ArticlesWatchersRelationship', 'examples'));
    }

    #[Test]
    public function aResourceAndARelationshipDocumentAreRefusedAMemberWithoutItsPivot(): void
    {
        $document = \json_decode((new OpenApiProjector())->project($this->server(self::tags()))->toJsonString(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);
        $validator = new Validator();
        $validator->resolver()?->registerRaw((object) ['components' => $document->components], self::ROOT);

        $member = static fn(?array $meta): array => ['type' => 'tags', 'id' => 'tag-1', ...($meta === null ? [] : ['meta' => $meta])];
        $resource = static fn(?array $meta): array => ['data' => [
            'type' => 'articles',
            'id' => 'art-1',
            'relationships' => ['tags' => ['data' => [$member($meta)]]],
        ]];
        $relationship = static fn(?array $meta): array => ['data' => [$member($meta)]];

        foreach (['ArticlesDocument' => $resource, 'ArticlesTagsRelationshipDocument' => $relationship] as $component => $build) {
            $schema = (object) ['$ref' => self::ROOT . '#/components/schemas/' . $component];
            $valid = static fn(?array $meta): bool => $validator->validate(\json_decode(\json_encode($build($meta), \JSON_THROW_ON_ERROR)), $schema)->isValid();

            self::assertTrue($valid(['pivot' => ['position' => 1]]), $component);
            self::assertFalse($valid(null), $component . ' without meta');
            self::assertFalse($valid([]), $component . ' without meta.pivot');
        }
    }

    private static function tags(): FakeRelationMetadata
    {
        return new FakeRelationMetadata('tags', ['tags'], true, pivotFields: [Integer::make('position')->required()->build()]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(RelationMetadataInterface $relation): array
    {
        return (new OpenApiProjector())->project($this->server($relation))->toArray();
    }

    private function server(RelationMetadataInterface $relation): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->example('art-1')->build()], relations: [$relation, FakeRelationMetadata::toMany('watchers', ['tags'])]),
                FakeTypeMetadata::resource(type: 'tags', fields: [Id::make()->example('tag-1')->build(), Str::make('name')->build()]),
            ],
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, \implode('.', $keys));
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
