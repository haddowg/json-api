<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Server\Entry;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceRegistry::class)]
#[CoversClass(Entry::class)]
#[CoversClass(NoResourceRegistered::class)]
final class ResourceRegistryTest extends TestCase
{
    #[Test]
    public function registrationReadsTypeStaticallyWithoutConstructing(): void
    {
        // ThrowingConstructorResource throws if instantiated. Registration reads
        // its static $type and must therefore succeed.
        ThrowingConstructorResource::$constructed = 0;

        $registry = new ResourceRegistry();
        $registry->register(ThrowingConstructorResource::class);

        self::assertSame(0, ThrowingConstructorResource::$constructed);
        self::assertTrue($registry->has('throwers'));
        self::assertSame(['throwers'], $registry->types());
    }

    #[Test]
    public function constructorFailureIsDeferredToFirstLookup(): void
    {
        ThrowingConstructorResource::$constructed = 0;

        $registry = new ResourceRegistry();
        $registry->register(ThrowingConstructorResource::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $registry->resourceFor('throwers');
    }

    #[Test]
    public function lazyConstructionViaResolverAndCaching(): void
    {
        $resolver = new RecordingResolver();
        $registry = new ResourceRegistry();
        $registry->setResolver(\Closure::fromCallable($resolver));
        $registry->register(PostResource::class);

        self::assertSame([], $resolver->calls);

        $first = $registry->resourceFor('posts');
        $second = $registry->resourceFor('posts');

        self::assertInstanceOf(PostResource::class, $first);
        self::assertSame($first, $second);
        self::assertSame([PostResource::class], $resolver->calls);
    }

    #[Test]
    public function plainNewFallbackWithoutResolver(): void
    {
        $registry = new ResourceRegistry();
        $registry->register(PostResource::class);

        self::assertInstanceOf(PostResource::class, $registry->resourceFor('posts'));
        self::assertInstanceOf(PostResource::class, $registry->serializerFor('posts'));
        self::assertInstanceOf(PostResource::class, $registry->hydratorFor('posts'));
    }

    #[Test]
    public function setResolverClearsBackToPlainNew(): void
    {
        $resolver = new RecordingResolver();
        $registry = new ResourceRegistry();
        $registry->setResolver(\Closure::fromCallable($resolver));
        $registry->setResolver(null);
        $registry->register(PostResource::class);

        self::assertInstanceOf(PostResource::class, $registry->resourceFor('posts'));
        self::assertSame([], $resolver->calls);
    }

    #[Test]
    public function emptyTypeRegistrationThrowsAtRegisterTime(): void
    {
        $registry = new ResourceRegistry();

        $this->expectException(\LogicException::class);
        $registry->register(UntypedResource::class);
    }

    #[Test]
    public function duplicateTypeRegistrationThrowsAtRegisterTime(): void
    {
        $registry = new ResourceRegistry();
        $registry->register(PostResource::class);

        $this->expectException(\LogicException::class);
        $registry->register(PostResource::class);
    }

    #[Test]
    public function unknownTypeThrowsNoResourceRegistered(): void
    {
        $registry = new ResourceRegistry();

        $this->expectException(NoResourceRegistered::class);
        $registry->serializerFor('nope');
    }

    #[Test]
    public function resolverReturningWrongTypeThrowsLogicException(): void
    {
        $registry = new ResourceRegistry();
        $registry->setResolver(static fn(string $class): object => new \stdClass());
        $registry->register(PostResource::class);

        $this->expectException(\LogicException::class);
        $registry->resourceFor('posts');
    }

    #[Test]
    public function barePairIsKeyedByExplicitTypeAndHasNoResourceFallback(): void
    {
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator(
            'widgets',
            serializer: CustomWidgetSerializer::class,
            hydrator: CustomPostHydrator::class,
        );

        self::assertTrue($registry->has('widgets'));
        self::assertTrue($registry->hasSerializerFor('widgets'));
        self::assertTrue($registry->hasHydratorFor('widgets'));
        self::assertInstanceOf(CustomWidgetSerializer::class, $registry->serializerFor('widgets'));
        self::assertInstanceOf(CustomPostHydrator::class, $registry->hydratorFor('widgets'));
    }

    #[Test]
    public function barePairResourceLookupThrowsNoResourceRegistered(): void
    {
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        $this->expectException(NoResourceRegistered::class);
        $registry->resourceFor('widgets');
    }

    #[Test]
    public function barePairMissingConcernThrowsNoResourceRegistered(): void
    {
        // A serializer-only bare pair has no hydrator and no Resource fallback.
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        $this->expectException(NoResourceRegistered::class);
        $registry->hydratorFor('widgets');
    }

    #[Test]
    public function barePairWithoutSerializerIsNotReportedBySerializerCheck(): void
    {
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator('widgets', hydrator: CustomPostHydrator::class);

        self::assertTrue($registry->has('widgets'));
        self::assertFalse($registry->hasSerializerFor('widgets'));
        self::assertTrue($registry->hasHydratorFor('widgets'));
        self::assertInstanceOf(CustomPostHydrator::class, $registry->hydratorFor('widgets'));
    }

    #[Test]
    public function barePairWithoutHydratorIsNotReportedByHydratorCheck(): void
    {
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        self::assertTrue($registry->has('widgets'));
        self::assertFalse($registry->hasHydratorFor('widgets'));
        self::assertTrue($registry->hasSerializerFor('widgets'));
    }

    #[Test]
    public function hasResourceForDistinguishesAResourceFromABarePair(): void
    {
        $registry = new ResourceRegistry();
        $registry->register(PostResource::class);
        $registry->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        // The presence-check mirror of resourceFor(): a type registered with a
        // Resource class reports a resource; a bare serializer/hydrator pair (or an
        // unregistered type) does not — so a caller can branch without catching.
        self::assertTrue($registry->hasResourceFor('posts'));
        self::assertFalse($registry->hasResourceFor('widgets'));
        self::assertFalse($registry->hasResourceFor('unregistered'));
    }

    #[Test]
    public function emptyBarePairTypeThrows(): void
    {
        $registry = new ResourceRegistry();

        $this->expectException(\LogicException::class);
        $registry->registerSerializerHydrator('', serializer: CustomWidgetSerializer::class);
    }

    #[Test]
    public function barePairWithNeitherConcernThrows(): void
    {
        $registry = new ResourceRegistry();

        $this->expectException(\LogicException::class);
        $registry->registerSerializerHydrator('widgets');
    }

    #[Test]
    public function overrideSerializerIsResolvedAheadOfResourceClass(): void
    {
        $registry = new ResourceRegistry();
        $registry->register(PostResource::class, serializer: CustomPostSerializer::class);

        self::assertInstanceOf(CustomPostSerializer::class, $registry->serializerFor('posts'));
        // Hydration still falls back to the Resource class.
        self::assertInstanceOf(PostResource::class, $registry->hydratorFor('posts'));
    }

    #[Test]
    public function injectsResolverIntoAStandaloneSerializerThatOptsIn(): void
    {
        // A bare serializer (no AbstractResource) that opts in via
        // SerializerResolverAwareInterface receives the registry as its resolver,
        // so it can render relationships from a standalone relation list.
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator('articles', serializer: StandaloneRelationSerializer::class);
        $registry->registerSerializerHydrator('authors', serializer: CustomWidgetSerializer::class);

        $serializer = $registry->serializerFor('articles');
        self::assertInstanceOf(StandaloneRelationSerializer::class, $serializer);

        $relationships = $serializer->getRelationships(['id' => '1'], new StubJsonApiRequest());
        self::assertArrayHasKey('author', $relationships);
    }

    #[Test]
    public function leavesABareSerializerThatDoesNotOptInUntouched(): void
    {
        // BC: a bare serializer that does not implement the aware interface is
        // never called and renders without a resolver, exactly as before.
        $registry = new ResourceRegistry();
        $registry->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        $serializer = $registry->serializerFor('widgets');
        self::assertInstanceOf(CustomWidgetSerializer::class, $serializer);
        // CustomWidgetSerializer does not implement SerializerResolverAwareInterface,
        // so the registry never injects a resolver; it renders as it always has.
        self::assertSame([], $serializer->getRelationships([], new StubJsonApiRequest()));
    }
}

/**
 * A bare serializer (not an {@see AbstractResource}) that renders relationships
 * from a standalone relation list, receiving its resolver through
 * {@see SerializerResolverAwareInterface} + {@see RendersRelationsTrait}.
 */
final class StandaloneRelationSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $resolver = null;

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function serializerResolver(): ?SerializerResolverInterface
    {
        return $this->resolver;
    }

    public function getType(mixed $object): string
    {
        return 'articles';
    }

    public function getId(mixed $object): string
    {
        return '1';
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

        /** @var list<RelationInterface> $relations */
        $relations = [BelongsTo::make('author', 'authors')];

        return self::relationshipCallables($relations, $this->resolver);
    }
}

final class ThrowingConstructorResource extends AbstractResource
{
    public static string $type = 'throwers';

    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;

        throw new \RuntimeException('boom');
    }

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class UntypedResource extends AbstractResource
{
    public static string $type = '';

    public function fields(): array
    {
        return [Id::make()];
    }
}
