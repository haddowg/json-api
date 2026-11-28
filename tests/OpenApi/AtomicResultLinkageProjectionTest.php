<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relationship operation's result carries the linkage its relationship endpoint returns,
 * so a relation typing its identifiers' `meta` types it in an atomic result too, and a
 * pivot relation's members there require their `meta.pivot`.
 */
#[CoversClass(OpenApiProjector::class)]
final class AtomicResultLinkageProjectionTest extends TestCase
{
    private const ROOT = 'urn:atomic-result-linkage';

    #[Test]
    public function aPivotRelationsResultIsItsResponseLinkage(): void
    {
        $document = (new OpenApiProjector())->project($this->server())->toArray();
        $members = $this->listAt($document, 'components', 'schemas', 'AtomicResult', 'properties', 'data', 'anyOf');

        self::assertContains([
            'type' => 'array',
            'description' => 'The linkage of the "tags" relationship of the "articles" type.',
            'items' => ['allOf' => [
                ['$ref' => '#/components/schemas/TagsResourceIdentifier'],
                [
                    'type' => 'object',
                    'properties' => ['meta' => [
                        'type' => 'object',
                        'properties' => ['pivot' => ['$ref' => '#/components/schemas/ArticlesTagsPivot']],
                        'required' => ['pivot'],
                    ]],
                    'required' => ['meta'],
                ],
            ]],
        ], $members);
    }

    #[Test]
    public function aPivotRelationsResultMembersRequireTheirPivot(): void
    {
        $validator = $this->validator();

        // An add, an update and a remove on the to-many each return its whole linkage.
        self::assertTrue($this->validates($validator, ['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['position' => 1]]]]]));
        self::assertTrue($this->validates($validator, ['data' => []]));
        self::assertFalse($this->validates($validator, ['data' => [['type' => 'tags', 'id' => '1']]]), 'without meta');
        self::assertFalse($this->validates($validator, ['data' => [['type' => 'tags', 'id' => '1', 'meta' => new \stdClass()]]]), 'without meta.pivot');
        self::assertFalse($this->validates($validator, ['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['colour' => 'red']]]]]), 'with an undeclared pivot field');
    }

    #[Test]
    public function aToOneDeclaringIdentifierMetaTypesItsResult(): void
    {
        $validator = $this->validator();

        self::assertTrue($this->validates($validator, ['data' => ['type' => 'people', 'id' => '1', 'meta' => ['role' => 'lead']]]));
        self::assertTrue($this->validates($validator, ['data' => null]));

        $document = (new OpenApiProjector())->project($this->server())->toArray();
        self::assertContains([
            'description' => 'The linkage of the "author" relationship of the "articles" type.',
            'allOf' => [
                ['$ref' => '#/components/schemas/PeopleResourceIdentifier'],
                ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/ArticlesAuthorIdentifierMeta']]],
            ],
        ], $this->listAt($document, 'components', 'schemas', 'AtomicResult', 'properties', 'data', 'anyOf'));
    }

    #[Test]
    public function anUntypedRelationKeepsTheSharedIdentifierUnion(): void
    {
        $validator = $this->validator();

        self::assertTrue($this->validates($validator, ['data' => [['type' => 'readers', 'id' => '1']]]));
        self::assertTrue($this->validates($validator, ['data' => ['type' => 'readers', 'id' => '1', 'meta' => ['anything' => true]]]));
    }

    #[Test]
    public function aPivotRelationNoOperationMutatesReturnsNoResult(): void
    {
        $validator = $this->validator();

        self::assertFalse($this->validates($validator, ['data' => [['type' => 'labels', 'id' => '1', 'meta' => ['pivot' => ['weight' => 1]]]]]));
    }

    private function server(): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                self::type('articles', [
                    new FakeRelationMetadata('tags', ['tags'], true, pivotFields: [Integer::make('position')->required()->build()]),
                    new FakeRelationMetadata('author', ['people'], false, identifierMetaFields: [Str::make('role')->build()]),
                    FakeRelationMetadata::toMany('readers', ['readers']),
                    new FakeRelationMetadata('labels', ['labels'], true, allowsReplace: false, allowsAdd: false, allowsRemove: false, pivotFields: [Integer::make('weight')->build()]),
                ]),
                self::type('tags'),
                self::type('people'),
                self::type('readers'),
                self::type('labels'),
            ],
            atomicOperations: new FakeAtomicOperationsMetadata(),
        );
    }

    /**
     * @param list<FakeRelationMetadata> $relations
     */
    private static function type(string $type, array $relations = []): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: $relations,
        );
    }

    private function validator(): Validator
    {
        $document = \json_decode((new OpenApiProjector())->project($this->server())->toJsonString(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);
        $validator = new Validator();
        $validator->resolver()?->registerRaw((object) ['components' => $document->components], self::ROOT);

        return $validator;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function validates(Validator $validator, array $result): bool
    {
        $schema = (object) ['$ref' => self::ROOT . '#/components/schemas/AtomicResult'];

        return $validator->validate(\json_decode(\json_encode($result, \JSON_THROW_ON_ERROR)), $schema)->isValid();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private function listAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, \implode('.', $keys));
            $value = $value[$key];
        }
        self::assertIsArray($value);
        self::assertTrue(\array_is_list($value));

        return $value;
    }
}
