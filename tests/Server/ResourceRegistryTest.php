<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Server\Entry;
use haddowg\JsonApi\Server\ResourceRegistry;
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
        self::assertInstanceOf(CustomPostHydrator::class, $registry->hydratorFor('widgets'));
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
