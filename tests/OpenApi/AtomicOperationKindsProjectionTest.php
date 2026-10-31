<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each atomic operation kind is its own component, and a valid operation matches exactly
 * one of them, so the `AtomicOperation` `oneOf` never sees two branches agree.
 */
#[CoversClass(OpenApiProjector::class)]
final class AtomicOperationKindsProjectionTest extends TestCase
{
    private const KINDS = [
        'AtomicAddOperation',
        'AtomicUpdateOperation',
        'AtomicRemoveOperation',
        'AtomicRelationshipAddOperation',
        'AtomicRelationshipUpdateOperation',
        'AtomicRelationshipRemoveOperation',
    ];

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function operations(): iterable
    {
        $toOne = ['type' => 'articles', 'id' => '1', 'relationship' => 'author'];
        $toMany = ['type' => 'articles', 'lid' => 'a1', 'relationship' => 'tags'];

        yield 'add with no target' => [['op' => 'add', 'data' => ['type' => 'articles', 'attributes' => ['title' => 'x']]], 'AtomicAddOperation'];
        yield 'add by ref' => [['op' => 'add', 'ref' => ['type' => 'articles'], 'data' => ['type' => 'articles', 'lid' => 'a1']], 'AtomicAddOperation'];
        yield 'add by href' => [['op' => 'add', 'href' => '/articles', 'data' => ['type' => 'articles']], 'AtomicAddOperation'];
        yield 'update by data' => [['op' => 'update', 'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'x']]], 'AtomicUpdateOperation'];
        yield 'update of an identifier-shaped resource' => [['op' => 'update', 'ref' => ['type' => 'people', 'id' => '1'], 'data' => ['type' => 'people', 'id' => '1']], 'AtomicUpdateOperation'];
        yield 'remove by ref' => [['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '1']], 'AtomicRemoveOperation'];
        yield 'remove by href' => [['op' => 'remove', 'href' => '/articles/1'], 'AtomicRemoveOperation'];
        yield 'relationship add by ref' => [['op' => 'add', 'ref' => $toMany, 'data' => [['type' => 'tags', 'id' => '1']]], 'AtomicRelationshipAddOperation'];
        yield 'relationship add by href' => [['op' => 'add', 'href' => '/articles/1/relationships/tags', 'data' => [['type' => 'tags', 'lid' => 't1']]], 'AtomicRelationshipAddOperation'];
        yield 'to-one update' => [['op' => 'update', 'ref' => $toOne, 'data' => ['type' => 'people', 'id' => '1']], 'AtomicRelationshipUpdateOperation'];
        yield 'to-one clear' => [['op' => 'update', 'ref' => $toOne, 'data' => null], 'AtomicRelationshipUpdateOperation'];
        yield 'to-many replace by href' => [['op' => 'update', 'href' => '/articles/1/relationships/tags', 'data' => []], 'AtomicRelationshipUpdateOperation'];
        yield 'relationship remove' => [['op' => 'remove', 'ref' => $toMany, 'data' => [['type' => 'tags', 'id' => '1']]], 'AtomicRelationshipRemoveOperation'];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('operations')]
    public function aValidOperationMatchesExactlyOneKind(array $operation, string $kind): void
    {
        $validator = $this->validator();

        $matches = [];
        foreach (self::KINDS as $candidate) {
            if ($this->validates($validator, $operation, $candidate)) {
                $matches[] = $candidate;
            }
        }

        self::assertSame([$kind], $matches);
        self::assertTrue($this->validates($validator, $operation, 'AtomicOperation'));
    }

    #[Test]
    public function anOperationMixingKindsMatchesNone(): void
    {
        $validator = $this->validator();

        // A relationship target with a resource payload, and a resource target with linkage.
        self::assertFalse($this->validates($validator, ['op' => 'add', 'href' => '/articles/1/relationships/tags', 'data' => ['type' => 'articles']], 'AtomicOperation'));
        self::assertFalse($this->validates($validator, ['op' => 'add', 'ref' => ['type' => 'articles'], 'data' => [['type' => 'tags', 'id' => '1']]], 'AtomicOperation'));
        // A remove of a resource carries no data.
        self::assertFalse($this->validates($validator, ['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '1'], 'data' => null], 'AtomicOperation'));
    }

    #[Test]
    public function aResultCarriesAResourceOrLinkage(): void
    {
        $validator = $this->validator();

        foreach ([
            ['data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'x']]],
            ['data' => ['type' => 'people', 'id' => '1']],
            ['data' => [['type' => 'tags', 'id' => '1']]],
            ['data' => null],
            new \stdClass(),
        ] as $result) {
            self::assertTrue($this->validates($validator, $result, 'AtomicResult'), (string) \json_encode($result));
        }

        self::assertFalse($this->validates($validator, ['data' => ['type' => 'robots', 'id' => '1']], 'AtomicResult'));
    }

    /**
     * @param array<array-key, mixed>|\stdClass $instance
     */
    private function validates(Validator $validator, array|\stdClass $instance, string $component): bool
    {
        $schema = \json_decode((string) \json_encode(['$ref' => 'urn:atomic-kinds#/components/schemas/' . $component]));
        self::assertInstanceOf(\stdClass::class, $schema);

        return $validator->validate(\json_decode((string) \json_encode($instance)), $schema)->isValid();
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
        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                self::type('articles', [FakeRelationMetadata::toOne('author', ['people']), FakeRelationMetadata::toMany('tags', ['tags'])]),
                self::type('people'),
                self::type('tags'),
            ],
            atomicOperations: new FakeAtomicOperationsMetadata(),
        ))->toArray();

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);
        $decoded = \json_decode((string) \json_encode(['components' => $document['components'] ?? []]));
        self::assertInstanceOf(\stdClass::class, $decoded);
        $resolver->registerRaw($decoded, 'urn:atomic-kinds');

        return $validator;
    }
}
