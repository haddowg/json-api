<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\ThroughReadGateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThroughReadAccess::class)]
#[CoversClass(RelationHop::class)]
#[CoversClass(ThroughPathResolver::class)]
#[CoversClass(ArrayFilterHandler::class)]
final class ThroughReadAccessTest extends TestCase
{
    #[Test]
    public function aPathEndingOnAFieldReachesThroughEveryRelationBeforeIt(): void
    {
        self::assertSame(
            [['comments', 'posts', 'comments'], ['author', 'comments', 'comments.author']],
            self::described($this->access()->hopsAlong('comments.author.name', $this->posts(), 'posts')),
        );
    }

    #[Test]
    public function aPathEndingOnARelationReachesThroughItToo(): void
    {
        self::assertSame(
            [['comments', 'posts', 'comments'], ['author', 'comments', 'comments.author']],
            self::described($this->access()->hopsAlong('comments.author', $this->posts(), 'posts')),
        );
    }

    #[Test]
    public function aPathOnAFieldReachesThroughNothing(): void
    {
        self::assertSame([], $this->access()->hopsAlong('title', $this->posts(), 'posts'));
    }

    #[Test]
    public function aSegmentNamesTheRelationNamedAfterIt(): void
    {
        // `pinned` shares the `comments` storage member, but the segment names `comments`.
        self::assertSame([['comments', 'posts', 'comments']], self::described($this->access()->hopsAlong('comments.body', $this->posts(), 'posts')));
    }

    #[Test]
    public function aSharedStorageMemberNamedAfterNoneOfItsRelationsReachesThroughEachOfThem(): void
    {
        self::assertSame(
            [['writer', 'posts', 'people'], ['editor', 'posts', 'people']],
            self::described($this->access()->hopsAlong('people.name', $this->posts(), 'posts')),
        );
    }

    #[Test]
    public function aPolymorphicRelationEndsTheWalk(): void
    {
        self::assertSame([['subject', 'posts', 'subject']], self::described($this->access()->hopsAlong('subject.title', $this->posts(), 'posts')));
    }

    #[Test]
    public function everyTraversingFilterReportsItsHopsGroupsIncluded(): void
    {
        $access = $this->access();
        $group = WhereAny::make(
            'search',
            Where::make('title'),
            WhereThrough::make('commentBody', 'comments.body'),
            WhereThroughIn::make('authors', 'comments.author'),
        )->build();

        self::assertSame([['comments', 'posts', 'comments'], ['comments', 'posts', 'comments'], ['author', 'comments', 'comments.author']], self::described($access->hopsOf($group, $this->posts(), 'posts')));
        self::assertSame([['comments', 'posts', 'comments']], self::described($access->hopsOf(WhereHas::make('comments'), $this->posts(), 'posts')));
        self::assertSame([['comments', 'posts', 'comments']], self::described($access->hopsOf(WhereDoesntHave::make('comments'), $this->posts(), 'posts')));
        self::assertSame([], $access->hopsOf(Where::make('title')->build(), $this->posts(), 'posts'));
    }

    #[Test]
    public function theGateDecidesWhichHopsAreRefused(): void
    {
        $gate = new class implements ThroughReadGateInterface {
            public function canReadThrough(RelationHop $hop, JsonApiRequestInterface $request): bool
            {
                return $hop->relation->name() !== 'author';
            }
        };
        $access = new ThroughReadAccess($this->resolver(), $gate);
        $hops = $access->hopsAlong('comments.author.name', $this->posts(), 'posts');

        self::assertSame([['author', 'comments', 'comments.author']], self::described($access->refused($hops, $this->createStub(JsonApiRequestInterface::class))));
        self::assertSame([], $this->access()->refused($hops, $this->createStub(JsonApiRequestInterface::class)));
        self::assertSame($gate, $access->gate());
    }

    #[Test]
    public function anAdmittedHopHidesTheRowsItDropsFromMatching(): void
    {
        $grace = (object) ['name' => 'grace'];
        $ada = (object) ['name' => 'ada'];
        $rows = [
            (object) ['id' => 1, 'comments' => [(object) ['body' => 'hidden', 'author' => $grace]]],
            (object) ['id' => 2, 'comments' => [(object) ['body' => 'shown', 'author' => $ada]]],
        ];
        $seen = [];
        $handler = (new ArrayFilterHandler())->withRowAdmission(static function (string $path, array $reached) use (&$seen): array {
            $seen[] = $path;

            return $path === 'comments'
                ? \array_values(\array_filter($reached, static fn(mixed $comment): bool => \is_object($comment) && ($comment->body ?? null) !== 'hidden'))
                : $reached;
        });

        self::assertSame([], $handler->apply(WhereThrough::make('who', 'comments.author.name')->build(), $rows, 'grace'));
        self::assertSame([$rows[1]], $handler->apply(WhereThrough::make('who', 'comments.author.name')->build(), $rows, 'ada'));
        self::assertSame([$rows[1]], $handler->apply(WhereHas::make('comments'), $rows, null));
        self::assertSame([$rows[0]], $handler->apply(WhereDoesntHave::make('comments'), $rows, null));
        self::assertContains('comments.author', $seen);
        self::assertContains('comments.author.name', $seen);

        // Without admission every row matches as before.
        self::assertSame([$rows[0]], (new ArrayFilterHandler())->apply(WhereThrough::make('who', 'comments.author.name')->build(), $rows, 'grace'));
    }

    private function access(): ThroughReadAccess
    {
        return new ThroughReadAccess($this->resolver());
    }

    private function resolver(): ThroughPathResolver
    {
        $inventories = [
            'posts' => $this->posts(),
            'comments' => [Id::make()->build(), Str::make('body')->build(), BelongsTo::make('author', 'people')->build()],
            'people' => [Id::make()->build(), Str::make('name')->build()],
        ];

        return new ThroughPathResolver(static fn(string $type): ?array => $inventories[$type] ?? null);
    }

    /**
     * @return list<FieldInterface>
     */
    private function posts(): array
    {
        return [
            Id::make()->build(),
            Str::make('title')->build(),
            HasMany::make('pinned', 'comments')->storedAs('comments')->build(),
            HasMany::make('comments', 'comments')->build(),
            BelongsTo::make('writer', 'people')->storedAs('people')->build(),
            BelongsTo::make('editor', 'people')->storedAs('people')->build(),
            MorphTo::make('subject', ['posts', 'comments'])->build(),
        ];
    }

    /**
     * @param list<RelationHop> $hops
     *
     * @return list<array{string, string, string}>
     */
    private static function described(array $hops): array
    {
        return \array_map(static fn(RelationHop $hop): array => [$hop->relation->name(), $hop->parentType, $hop->path], $hops);
    }
}
