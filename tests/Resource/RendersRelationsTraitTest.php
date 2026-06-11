<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RendersRelationsTrait::class)]
final class RendersRelationsTraitTest extends TestCase
{
    #[Test]
    public function buildsACallableMapKeyedByRelationName(): void
    {
        $serializer = new StandaloneRelationsSerializer([
            BelongsTo::make('author')->type('authors'),
            HasMany::make('comments')->type('comments'),
        ]);
        $serializer->setSerializerResolver(new StubSerializerResolver('authors', 'comments'));

        $request = new StubJsonApiRequest();
        $relationships = $serializer->getRelationships(['id' => '1'], $request);

        self::assertSame(['author', 'comments'], \array_keys($relationships));
        foreach ($relationships as $callable) {
            self::assertIsCallable($callable);
        }
    }

    #[Test]
    public function callablesBuildRelationshipObjectsOfTheRightCardinality(): void
    {
        $serializer = new StandaloneRelationsSerializer([
            BelongsTo::make('author')->type('authors'),
            HasMany::make('comments')->type('comments'),
        ]);
        $serializer->setSerializerResolver(new StubSerializerResolver('authors', 'comments'));

        $request = new StubJsonApiRequest();
        $model = ['id' => '1', 'author' => ['id' => '7'], 'comments' => []];
        $relationships = $serializer->getRelationships($model, $request);

        $author = $relationships['author']($model, $request, 'author');
        $comments = $relationships['comments']($model, $request, 'comments');

        self::assertInstanceOf(AbstractRelationship::class, $author);
        self::assertInstanceOf(AbstractRelationship::class, $comments);
        self::assertInstanceOf(ToOneRelationship::class, $author);
        self::assertInstanceOf(ToManyRelationship::class, $comments);
    }

    #[Test]
    public function noRelationsYieldsAnEmptyMap(): void
    {
        $serializer = new StandaloneRelationsSerializer([]);
        $serializer->setSerializerResolver(new StubSerializerResolver());

        self::assertSame([], $serializer->getRelationships(['id' => '1'], new StubJsonApiRequest()));
    }
}

/**
 * A serializer that is **not** an {@see \haddowg\JsonApi\Resource\AbstractResource}
 * yet renders relationships from a standalone relation list via
 * {@see RendersRelationsTrait}, receiving its resolver through
 * {@see SerializerResolverAwareInterface}.
 */
final class StandaloneRelationsSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $resolver = null;

    /**
     * @param list<\haddowg\JsonApi\Resource\Field\RelationInterface> $relations
     */
    public function __construct(private readonly array $relations) {}

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function getType(mixed $object): string
    {
        return 'standalones';
    }

    public function getId(mixed $object): string
    {
        return \is_array($object) && isset($object['id']) && \is_scalar($object['id']) ? (string) $object['id'] : '0';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        if ($this->resolver === null) {
            return [];
        }

        return self::relationshipCallables($this->relations, $this->resolver);
    }
}
