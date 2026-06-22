<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\EagerLoadValidator;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EagerLoadValidator::class)]
final class EagerLoadValidatorTest extends TestCase
{
    /**
     * @param list<AbstractResource> $resources
     */
    private function validator(array $resources): EagerLoadValidator
    {
        return new EagerLoadValidator($this->resolver($resources));
    }

    /**
     * @param list<AbstractResource> $resources
     */
    private function resolver(array $resources): SerializerResolverInterface
    {
        $byType = [];
        foreach ($resources as $resource) {
            $byType[$resource->getType(null)] = $resource;
        }

        return new class ($byType) implements SerializerResolverInterface {
            /**
             * @param array<string, SerializerInterface> $byType
             */
            public function __construct(private readonly array $byType) {}

            public function serializerFor(string $type): SerializerInterface
            {
                return $this->byType[$type] ?? throw new \RuntimeException('no serializer for ' . $type);
            }

            public function hasSerializerFor(string $type): bool
            {
                return isset($this->byType[$type]);
            }

            public function relationshipLoadState(): ?RelationshipLoadStateInterface
            {
                return null;
            }

            public function relationshipCount(): ?RelationshipCountInterface
            {
                return null;
            }

            public function relationshipPagination(): ?RelationshipPaginationInterface
            {
                return null;
            }

            public function relationshipLinkage(): ?\haddowg\JsonApi\Serializer\RelationshipLinkageInterface
            {
                return null;
            }
        };
    }

    #[Test]
    public function passesForASafeNestedToOneChain(): void
    {
        // on('author.country'): hidden to-one -> visible to-one. Every segment is
        // to-one, so the chain is valid (no throw).
        $validator = $this->validator([new BookWithNestedOn(), new AuthorResource(), new CountryResource()]);

        $validator->validate('books', new BookWithNestedOn());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function passesForAnOnBackingHiddenToOne(): void
    {
        // A single-hop on() backing relation that is hidden and to-one is valid.
        $validator = $this->validator([new FlattenedBook(), new AuthorResource()]);

        $validator->validate('books', new FlattenedBook());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function passesForAVisibleToOneSegment(): void
    {
        // A segment may be visible (not hidden): the chain is to-one, so loading it
        // never flips its linkage rendering — no leakage to guard against.
        $validator = $this->validator([new BookOnVisibleToOne(), new AuthorResource()]);

        $validator->validate('books', new BookOnVisibleToOne());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function throwsForALeafToManySegment(): void
    {
        // on('tags.label') would require `tags` to be to-one; a to-many leaf is not
        // flattenable. Here on('tags') alone names a to-many leaf -> throw.
        $validator = $this->validator([new BookOnToMany(), new TagResource()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/to-many is not flattenable/');
        $validator->validate('books', new BookOnToMany());
    }

    #[Test]
    public function throwsWhenAnAncestorSegmentIsToMany(): void
    {
        // on('tags.creator'): the ANCESTOR `tags` is a to-many — a to-many at any
        // depth is not flattenable, so the whole chain is rejected.
        $validator = $this->validator([new BookOnToManyAncestor(), new TagResource(), new AuthorResource()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/to-many is not flattenable/');
        $validator->validate('books', new BookOnToManyAncestor());
    }

    #[Test]
    public function throwsForAnUnknownSegment(): void
    {
        $validator = $this->validator([new BookOnUnknown()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/unknown relation "nope"/');
        $validator->validate('books', new BookOnUnknown());
    }

    #[Test]
    public function throwsForAnUnknownNESTEDSegment(): void
    {
        // author resolves, but `author.bogus` does not.
        $validator = $this->validator([new BookOnNestedUnknown(), new AuthorResource()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/unknown relation "bogus"/');
        $validator->validate('books', new BookOnNestedUnknown());
    }

    #[Test]
    public function leavesAPolymorphicSegmentUnwalkedWithoutThrowing(): void
    {
        // A MorphTo (more than one related type) cannot be walked to a single next
        // type, so a chain traversing it is left unwalked — no throw, even though the
        // remaining segments could not be checked.
        $validator = $this->validator([new BookOnThroughMorph()]);

        $validator->validate('books', new BookOnThroughMorph());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function isANoOpForASerializerWithoutEagerLoads(): void
    {
        // A bare serializer that declares no eager loads is skipped entirely.
        $bare = new class implements SerializerInterface {
            public function getType(mixed $object): string
            {
                return 'bare';
            }

            public function getId(mixed $object): string
            {
                return '1';
            }

            public function getAttributes(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
            {
                return [];
            }

            public function getRelationships(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
            {
                return [];
            }

            public function getDefaultIncludedRelationships(mixed $object): array
            {
                return [];
            }

            public function getMeta(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
            {
                return [];
            }

            public function getLinks(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): ?\haddowg\JsonApi\Schema\Link\ResourceLinks
            {
                return null;
            }
        };

        $this->validator([])->validate('bare', $bare);

        $this->addToAssertionCount(1);
    }
}

/**
 * Flattens through a safe nested to-one chain `author.country` (every segment
 * to-one): the eager path derives from the on() attribute.
 */
final class BookWithNestedOn extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('countryName')->on('author.country')->storedAs('name'),
            BelongsTo::make('author', 'authors')->hidden(),
        ];
    }
}

/**
 * An author resource with a visible to-one `country`.
 */
final class AuthorResource extends AbstractResource
{
    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('country', 'countries'),
        ];
    }
}

final class CountryResource extends AbstractResource
{
    public static string $type = 'countries';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

final class FlattenedBook extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('authorName')->on('author')->storedAs('name'),
            BelongsTo::make('author', 'authors')->hidden(),
        ];
    }
}

/**
 * Flattens through a VISIBLE to-one `author` — a segment need not be hidden.
 */
final class BookOnVisibleToOne extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('authorName')->on('author')->storedAs('name'),
            BelongsTo::make('author', 'authors'),
        ];
    }
}

/**
 * Flattens via on('tags') against a to-many leaf — an author error.
 */
final class BookOnToMany extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('tagLabel')->on('tags')->storedAs('label'),
            HasMany::make('tags', 'tags')->hidden(),
        ];
    }
}

/**
 * Flattens via on('tags.creator') — the ancestor `tags` is a to-many.
 */
final class BookOnToManyAncestor extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('tagCreator')->on('tags.creator')->storedAs('name'),
            HasMany::make('tags', 'tags')->hidden(),
        ];
    }
}

final class TagResource extends AbstractResource
{
    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            BelongsTo::make('creator', 'authors'),
        ];
    }
}

final class BookOnUnknown extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('nopeName')->on('nope'),
        ];
    }
}

final class BookOnNestedUnknown extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('bogusName')->on('author.bogus'),
            BelongsTo::make('author', 'authors')->hidden(),
        ];
    }
}

final class BookOnThroughMorph extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('ownerName')->on('owner.anything'),
            MorphTo::make('owner', ['authors', 'publishers'])->hidden(),
        ];
    }
}
