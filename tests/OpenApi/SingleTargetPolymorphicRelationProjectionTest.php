<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresPolymorphicRelation;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\WriteOnlyRelationMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation polymorphic by declaration ({@see DeclaresPolymorphicRelation}) is projected
 * as a union even over one type, so it reads the way it will once a second type joins.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(RelationFacts::class)]
#[CoversClass(GeneratorContract::class)]
final class SingleTargetPolymorphicRelationProjectionTest extends TestCase
{
    #[Test]
    public function aSingleTargetToOneIsAOneMemberUnionInItsLinkageAndRelatedDocument(): void
    {
        $schemas = $this->arrAt($this->document(FakeRelationMetadata::morph('subject', ['people'])), 'components', 'schemas');

        self::assertSame(
            ['anyOf' => [['anyOf' => [['$ref' => '#/components/schemas/PeopleResourceIdentifier']]], ['type' => 'null']]],
            $this->arrAt($schemas, 'ArticlesSubjectRelationship', 'properties', 'data'),
        );
        self::assertSame(
            ['anyOf' => [['anyOf' => [['$ref' => '#/components/schemas/PeopleResource']]], ['type' => 'null']]],
            $this->arrAt($schemas, 'ArticlesSubjectRelatedDocument', 'properties', 'data'),
        );
        self::assertSame(
            ['anyOf' => [['anyOf' => [['$ref' => '#/components/schemas/PeopleResourceIdentifier']]], ['type' => 'null']]],
            $this->arrAt($schemas, 'ArticlesSubjectRelationshipDocument', 'properties', 'data'),
        );
    }

    #[Test]
    public function aSingleTargetToManyIsAOneMemberUnionInItsLinkageAndRelatedCollection(): void
    {
        $schemas = $this->arrAt($this->document(FakeRelationMetadata::morph('subjects', ['people'], toMany: true)), 'components', 'schemas');

        self::assertSame(
            ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/PeopleResourceIdentifier']]]],
            $this->arrAt($schemas, 'ArticlesSubjectsRelationship', 'properties', 'data'),
        );
        self::assertSame(
            ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/PeopleResource']]]],
            $this->arrAt($schemas, 'ArticlesSubjectsRelatedCollection', 'properties', 'data'),
        );
    }

    #[Test]
    public function addingASecondTypeOnlyAddsAMember(): void
    {
        $one = $this->arrAt($this->document(FakeRelationMetadata::morph('subject', ['people'])), 'components', 'schemas', 'ArticlesSubjectRelationship', 'properties', 'data');
        $two = $this->arrAt($this->document(FakeRelationMetadata::morph('subject', ['people', 'robots'])), 'components', 'schemas', 'ArticlesSubjectRelationship', 'properties', 'data');

        $members = $this->arrAt($two, 'anyOf', '0', 'anyOf');
        self::assertCount(2, $members);
        self::assertSame($one, ['anyOf' => [['anyOf' => [$members[0]]], ['type' => 'null']]]);
    }

    #[Test]
    public function itsEndpointsTakeNoParametersFromTheRelatedType(): void
    {
        $toOne = $this->arrAt($this->document(FakeRelationMetadata::morph('subject', ['people'])), 'paths');
        self::assertSame(['fields[people]'], $this->parameterNames($toOne, '/articles/{id}/subject'));
        self::assertSame([], $this->parameterNames($toOne, '/articles/{id}/relationships/subject'));

        $toMany = $this->arrAt($this->document(FakeRelationMetadata::morph('subjects', ['people'], toMany: true)), 'paths');
        self::assertSame(['fields[people]'], $this->parameterNames($toMany, '/articles/{id}/subjects'));
        self::assertSame([], $this->parameterNames($toMany, '/articles/{id}/relationships/subjects'));

        // The same relation declared monomorphic takes the related type's vocabulary.
        $plain = $this->arrAt($this->document(FakeRelationMetadata::toOne('subject', ['people'])), 'paths');
        self::assertSame(['filter[name]', 'fields[people]'], $this->parameterNames($plain, '/articles/{id}/subject'));
        self::assertSame(['filter[name]'], $this->parameterNames($plain, '/articles/{id}/relationships/subject'));
        $plainMany = $this->arrAt($this->document(new FakeRelationMetadata('subjects', ['people'], true)), 'paths');
        self::assertSame(['filter[name]', 'sort'], $this->parameterNames($plainMany, '/articles/{id}/relationships/subjects'));
    }

    #[Test]
    public function itsLinkageExampleShowsItsOneType(): void
    {
        $paths = $this->arrAt($this->document(FakeRelationMetadata::morph('subject', ['people'])), 'paths');

        self::assertSame(
            ['type' => 'people', 'id' => 'per-1'],
            $this->arrAt($paths, '/articles/{id}/relationships/subject', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data'),
        );
        self::assertSame(
            ['type' => 'people', 'id' => 'per-1'],
            $this->arrAt($paths, '/articles/{id}/relationships/subject', 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'example', 'data'),
        );
    }

    #[Test]
    public function onlyADocumentWithASingleTargetPolymorphicRelationCarriesItsContract(): void
    {
        self::assertSame(GeneratorContract::toArray(singleTargetPolymorphism: true)['contract'], $this->contract(FakeRelationMetadata::morph('subject', ['people'])));
        self::assertSame(GeneratorContract::BASELINE, $this->contract(FakeRelationMetadata::morph('subject', ['people', 'robots'])));
        self::assertSame(GeneratorContract::BASELINE, $this->contract(FakeRelationMetadata::toOne('subject', ['people'])));
    }

    #[Test]
    public function metadataThatDoesNotDeclarePolymorphismProjectsAsBefore(): void
    {
        $plain = $this->document(FakeRelationMetadata::toOne('subject', ['people']));

        // Reports false.
        self::assertSame($plain, $this->document(new FakeRelationMetadata('subject', ['people'], false, polymorphic: false)));
        // A wrapper that does not implement the interface hides the declaration.
        self::assertSame($plain, $this->document(new WriteOnlyRelationMetadata(FakeRelationMetadata::morph('subject', ['people']), writeOnly: false)));
    }

    private function contract(RelationMetadataInterface $relation): int
    {
        $generator = $this->arrAt($this->document($relation), 'info', 'x-generator');
        self::assertIsInt($generator['contract']);

        return $generator['contract'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(RelationMetadataInterface $relation): array
    {
        $person = static fn(string $type, string $id): FakeTypeMetadata => FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->example($id)->build(), Str::make('name')->example('Ada')->build()],
            filters: [Where::make('name')->example('Ada')->build()],
            sorts: [SortByField::make('name')],
        );

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->example('art-1')->build(), Str::make('title')->example('Hello')->build()], relations: [$relation]),
                $person('people', 'per-1'),
                $person('robots', 'bot-1'),
            ],
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $paths
     *
     * @return list<string>
     */
    private function parameterNames(array $paths, string $path): array
    {
        $operation = $this->arrAt($paths, $path, 'get');
        $parameters = $operation['parameters'] ?? [];
        self::assertIsArray($parameters);
        $names = [];
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            self::assertIsString($parameter['name']);
            $names[] = $parameter['name'];
        }

        return $names;
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
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
