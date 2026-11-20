<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Through;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\FiltersThrough;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use haddowg\JsonApi\Resource\Through\RelationPath;
use haddowg\JsonApi\Resource\Through\SortsThrough;
use haddowg\JsonApi\Resource\ThroughReadGateInterface;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `pivot` segments in a `->through()` path: the pivot row of the relation a filter or
 * sort runs under when the path starts with one, the pivot rows of the relation before it
 * anywhere else, a pivot field as the leaf, and a relation the pivot row holds as a hop.
 *
 * Albums have tracks; a track is on playlists through pivot rows carrying `addedAt` and
 * `position`, which hold the user who added it and the curators who approved it. Tags
 * are a plain join and favourites a polymorphic relation with pivot fields.
 */
#[CoversClass(ThroughPathResolver::class)]
#[CoversClass(RelationPath::class)]
#[CoversClass(ThroughReadAccess::class)]
#[CoversClass(RelationHop::class)]
final class ThroughPivotSegmentsTest extends TestCase
{
    #[Test]
    public function aLeadingPivotReadsAPivotFieldOfTheRelationItRunsUnder(): void
    {
        $filter = $this->resolveOnPlaylists(DateRange::make('addedAt', 'addedAt')->through('pivot')->build());
        $path = $this->pathOf($filter);

        self::assertTrue($path->startsWithPivot());
        self::assertTrue($path->endsOnPivot());
        self::assertFalse($path->reachesMany());
        self::assertSame('addedAt', $path->leafColumn());
        self::assertTrue($path->hops[0]->pivot);
        self::assertSame($this->playlists()->relation, $path->hops[0]->relation);
        self::assertSame('tracks', $path->hops[0]->parentType);
        self::assertInstanceOf(DateTime::class, $path->leaf);
    }

    #[Test]
    public function aLeadingPivotGoesOnThroughARelationThePivotRowHolds(): void
    {
        $path = $this->pathOf($this->resolveOnPlaylists(Where::make('addedBy', 'name')->through('pivot.addedBy')->build()));

        self::assertCount(2, $path->hops);
        self::assertFalse($path->hops[1]->pivot);
        self::assertSame('users', $path->leafType());
        self::assertSame('display_name', $path->leafColumn());
        self::assertFalse($path->endsOnPivot());
        self::assertFalse($path->reachesMany());
    }

    #[Test]
    public function aPivotMidPathReachesThePivotRowsOfTheRelationBeforeIt(): void
    {
        $path = $this->pathOf($this->resolveOnAlbums(Where::make('curator', 'name')->through('tracks.playlists.pivot.addedBy')->build()));

        self::assertSame(['tracks', 'playlist_links', 'addedBy'], $path->storageSegments());
        self::assertFalse($path->hops[0]->pivot);
        self::assertTrue($path->hops[1]->pivot);
        self::assertSame('tracks.playlist_links.pivot', $path->hops[1]->path);
        self::assertSame('tracks', $path->hops[1]->parentType);
        self::assertFalse($path->startsWithPivot());
        self::assertTrue($path->isToManyHop(1));
        self::assertSame('users', $path->leafType());

        $leaf = $this->pathOf($this->resolveOnAlbums(DateRange::make('addedAt', 'addedAt')->through('tracks.playlists.pivot')->build()));
        self::assertTrue($leaf->endsOnPivot());
        self::assertSame('addedAt', $leaf->leafColumn());
    }

    #[Test]
    public function pivotSegmentsNest(): void
    {
        $path = $this->pathOf($this->resolveOnPlaylists(Where::make('deep', 'position')->through('pivot.addedBy.playlists.pivot')->build()));

        self::assertSame([true, false, true], \array_map(static fn(RelationHop $hop): bool => $hop->pivot, $path->hops));
        self::assertSame('position', $path->leafColumn());
    }

    #[Test]
    public function aPolymorphicRelationWithPivotFieldsMayLeadToItsPivot(): void
    {
        $path = $this->pathOf($this->resolveOnAlbums(Where::make('rank', 'rank')->through('tracks.favourites.pivot')->build()));

        self::assertTrue($path->endsOnPivot());
        self::assertSame('rank', $path->leafColumn());
    }

    #[Test]
    public function anExistenceFilterTestsARelationThePivotRowHolds(): void
    {
        $filter = $this->resolveOnPlaylists(WhereDoesntHave::make('uncurated', 'curators')->through('pivot'));
        $path = $this->pathOf($filter);

        self::assertSame('curators', $path->leafRelation()?->name());
        $hops = $this->access()->hopsOf($filter, self::types()['playlists'], 'playlists', $this->playlists());
        self::assertCount(2, $hops);
        self::assertTrue($hops[0]->pivot);
        self::assertSame('curators', $hops[1]->relation->name());
    }

    #[Test]
    public function anIdFilterComparesTheIdOfATypeThePivotRowReaches(): void
    {
        $filter = $this->resolveOnPlaylists(WhereIdIn::make('adder')->through('pivot.addedBy')->build());
        self::assertInstanceOf(FiltersThrough::class, $filter);
        self::assertSame(["21"], $filter->leafValue("12"));
    }

    #[Test]
    public function aSortReadsALeadingPivotButNotOneAfterAToMany(): void
    {
        $resolver = $this->resolver();
        $byField = $resolver->resolveSort(SortByField::make('addedAt', 'addedAt')->through('pivot'), self::types()['playlists'], $this->playlists());
        self::assertInstanceOf(SortsThrough::class, $byField);
        self::assertSame('addedAt', $byField->resolvedThrough()?->leafColumn());

        $byAdder = $resolver->resolveSort(SortByField::make('adder', 'name')->through('pivot.addedBy'), self::types()['playlists'], $this->playlists());
        self::assertInstanceOf(SortsThrough::class, $byAdder);
        self::assertSame('users', $byAdder->resolvedThrough()?->leafType());

        $this->expectExceptionMessage('whose `pivot` follows a to-many relation');
        $resolver->resolveSort(SortByField::make('addedAt', 'addedAt')->through('playlists.pivot'), self::types()['tracks']);
    }

    #[Test]
    public function aSortThroughAToManyRelationThePivotRowHoldsIsRefused(): void
    {
        $this->expectExceptionMessage('crosses a to-many relation');
        $this->resolver()->resolveSort(SortByField::make('curator', 'name')->through('pivot.curators'), self::types()['playlists'], $this->playlists());
    }

    /**
     * @return iterable<string, array{FilterInterface|SortInterface, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a leading pivot under no relation' => [Where::make('x', 'addedAt')->through('pivot')->build(), 'playlists', 'it runs under none'];
        yield 'a pivot after a relation with no pivot rows' => [Where::make('x', 'name')->through('tags.pivot')->build(), 'tracks', 'which has no pivot rows'];
        yield 'a pivot after a pivot' => [Where::make('x', 'addedAt')->through('playlists.pivot.pivot')->build(), 'tracks', 'follows another pivot row'];
        yield 'a member no pivot row relation names' => [Where::make('x', 'name')->through('playlists.pivot.nobody')->build(), 'tracks', 'is not a relation the pivot rows of "playlists" hold'];
        yield 'a computed pivot field' => [Where::make('x', 'note')->through('playlists.pivot')->build(), 'tracks', 'stores no value of its own'];
        yield 'an undeclared pivot field' => [Where::make('x', 'colour')->through('playlists.pivot')->build(), 'tracks', 'on the pivot of relation "playlists", which declares no such field'];
        yield 'an id of a pivot row' => [WhereIdIn::make('x')->through('playlists.pivot')->build(), 'tracks', 'a pivot row has none'];
        yield 'a relation named pivot' => [Where::make('x', 'name')->through('pivot')->build(), 'shadows', 'declares a relation named "pivot"'];
    }

    #[Test]
    public function eachMisplacedPivotIsRefused(): void
    {
        foreach (self::refusals() as $case => [$filter, $type, $message]) {
            try {
                \assert($filter instanceof FilterInterface);
                $this->resolver()->resolve($filter, self::types()[$type]);
                self::fail(\sprintf('"%s" resolved.', $case));
            } catch (\LogicException $refused) {
                self::assertStringContainsString($message, $refused->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function aPathGoingOnPastAPivotNeedsTheDataLayerToNameThePivotRowsRelations(): void
    {
        $types = self::types();
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null);

        $this->expectExceptionMessage('is not a relation the pivot rows of "playlists" hold');
        $resolver->resolve(Where::make('x', 'name')->through('pivot.addedBy')->build(), $types['playlists'], $this->playlists());
    }

    #[Test]
    public function aPivotHopIsGatedByItsRelationAndAPivotRowsRelationByItsType(): void
    {
        $asked = [];
        $gate = new class ($asked) implements ThroughReadGateInterface {
            /**
             * @param list<string> $asked
             */
            public function __construct(public array &$asked) {}

            public function canReadThrough(RelationHop $hop, JsonApiRequestInterface $request): bool
            {
                $this->asked[] = ($hop->pivot ? 'pivot of ' : '') . $hop->relation->name();

                return $hop->relation->name() !== 'addedBy';
            }
        };
        $access = new ThroughReadAccess($this->resolver(), $gate);

        $hops = $access->hopsOf(Where::make('curator', 'name')->through('tracks.playlists.pivot.addedBy')->build(), self::types()['albums'], 'albums');
        $refused = $access->refused($hops, $this->createStub(JsonApiRequestInterface::class));

        self::assertSame(['tracks', 'pivot of playlists', 'addedBy'], $gate->asked);
        self::assertSame(['addedBy'], \array_map(static fn(RelationHop $hop): string => $hop->relation->name(), $refused));
        self::assertSame('albums', $hops[0]->parentType);

        $leading = $access->hopsOfSort(SortByField::make('addedAt', 'addedAt')->through('pivot'), self::types()['playlists'], 'playlists', $this->playlists());
        self::assertTrue($leading[0]->pivot);
        self::assertSame('tracks', $leading[0]->parentType);
    }

    #[Test]
    public function aDeprecatedPivotPathReachesTheSameHopsAsItsThroughForm(): void
    {
        $access = $this->access();
        $fields = self::types()['playlists'];
        $hops = fn(FilterInterface $filter): array => \array_map(
            static fn(RelationHop $hop): array => [$hop->relation->name(), $hop->parentType, $hop->path, $hop->pivot],
            $access->hopsOf($filter, $fields, 'playlists', $this->playlists()),
        );

        foreach ([
            [WhereThrough::make('adder', 'pivot.addedBy.display_name')->build(), Where::make('adder', 'name')->through('pivot.addedBy')->build()],
            [WhereThroughIn::make('adder', 'pivot.addedBy')->build(), WhereIdIn::make('adder')->through('pivot.addedBy')->build()],
            [WhereDoesntHave::make('uncurated', 'pivot.curators'), WhereDoesntHave::make('uncurated', 'curators')->through('pivot')],
            [WhereThrough::make('listed', 'pivot.addedBy.playlists.name')->build(), Where::make('listed', 'name')->through('pivot.addedBy.playlists')->build()],
        ] as [$deprecated, $migrated]) {
            self::assertSame($hops($migrated), $hops($deprecated), $deprecated->key());
        }

        self::assertSame([['playlists', 'tracks', 'pivot', true], ['addedBy', '', 'pivot.addedBy', false]], $hops(WhereThrough::make('adder', 'pivot.addedBy.display_name')->build()));
        // A pivot column, and a member the pivot row holds no relation under, reach the pivot row alone.
        self::assertSame([['playlists', 'tracks', 'pivot', true]], $hops(WhereThrough::make('at', 'pivot.position')->build()));
        // Run under no relation, `pivot` is just a segment no relation is stored under.
        self::assertSame([], $access->hopsOf(WhereThrough::make('adder', 'pivot.addedBy.display_name')->build(), $fields, 'playlists'));
    }

    #[Test]
    public function aDeprecatedPivotPathPastAnEntityNoSingleTypeServesReachesThePivotRowAlone(): void
    {
        $types = self::types();
        $rows = new class implements PivotRowRelations {
            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                throw new \LogicException('several types serve it');
            }
        };
        $access = new ThroughReadAccess(new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null, $rows));

        $hops = $access->hopsOf(WhereThrough::make('adder', 'pivot.addedBy.display_name')->build(), $types['playlists'], 'playlists', $this->playlists());

        self::assertCount(1, $hops);
        self::assertTrue($hops[0]->pivot);
    }

    #[Test]
    public function theInMemoryWalkRefusesAPivotRow(): void
    {
        $path = $this->pathOf($this->resolveOnAlbums(DateRange::make('addedAt', 'addedAt')->through('tracks.playlists.pivot')->build()));

        $this->expectExceptionMessage('reaches a pivot row');
        $path->reach((object) ['tracks' => []]);
    }

    #[Test]
    public function aPivotOnTheFirstRelationAsksForThePivotRowsOfTheTypeThePathStartsFrom(): void
    {
        $types = self::types();
        $rows = new class implements PivotRowRelations {
            /**
             * @var list<string>
             */
            public array $parentTypes = [];

            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                $this->parentTypes[] = $parentType;

                return $parentType === 'tracks' && $member === 'addedBy' ? BelongsTo::make('addedBy', 'users')->build() : null;
            }
        };
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null, $rows);

        $filter = $resolver->resolve(Where::make('adder', 'name')->through('playlists.pivot.addedBy')->build(), $types['tracks'], type: 'tracks');
        $path = $this->pathOf($filter);
        self::assertSame('tracks', $path->hops[0]->parentType);
        self::assertSame('users', $path->leafType());

        // The access helpers name the type the filter runs against, so they ask the same.
        $hops = (new ThroughReadAccess($resolver))->hopsOf(Where::make('adder', 'name')->through('playlists.pivot.addedBy')->build(), $types['tracks'], 'tracks');
        self::assertSame('users', $hops[\count($hops) - 1]->relatedType());
        self::assertSame(['tracks'], \array_values(\array_unique($rows->parentTypes)));
    }

    #[Test]
    public function aPivotOnTheFirstRelationOfARelationsFiltersAsksForItsRelatedType(): void
    {
        $types = self::types();
        $parents = [];
        $rows = new class ($parents) implements PivotRowRelations {
            /**
             * @param list<string> $parents
             */
            public function __construct(public array $parents) {}

            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                $this->parents[] = $parentType;

                return $member === 'addedBy' ? BelongsTo::make('addedBy', 'users')->build() : null;
            }
        };
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null, $rows);
        $album = $types['albums'][1];
        \assert($album instanceof RelationInterface);

        // An album's tracks filtered by a pivot of the tracks' own playlists: the path starts
        // from the related type of the relation it runs under.
        $resolver->resolve(Where::make('adder', 'name')->through('playlists.pivot.addedBy')->build(), $types['tracks'], new RelationHop($album, 'albums', 'tracks'));
        self::assertSame(['tracks'], $rows->parents);
    }

    private function pathOf(FilterInterface $filter): RelationPath
    {
        self::assertInstanceOf(FiltersThrough::class, $filter);

        return $filter->resolvedThrough() ?? throw new \LogicException('unresolved');
    }

    private function resolveOnPlaylists(FilterInterface $filter): FilterInterface
    {
        return $this->resolver()->resolve($filter, self::types()['playlists'], $this->playlists());
    }

    private function resolveOnAlbums(FilterInterface $filter): FilterInterface
    {
        return $this->resolver()->resolve($filter, self::types()['albums']);
    }

    private function access(): ThroughReadAccess
    {
        return new ThroughReadAccess($this->resolver());
    }

    /**
     * The tracks type's `playlists`, as the playlists' related endpoints run under it.
     */
    private function playlists(): RelationHop
    {
        $relation = self::types()['tracks'][2];
        \assert($relation instanceof BelongsToMany);

        return new RelationHop($relation, 'tracks', 'playlist_links');
    }

    private function resolver(): ThroughPathResolver
    {
        $types = self::types();

        return new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null, self::pivotRows());
    }

    private static function pivotRows(): PivotRowRelations
    {
        static $rows = null;

        return $rows ??= new class implements PivotRowRelations {
            /**
             * @var array<string, RelationInterface>
             */
            private array $relations = [];

            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                if ($relation->name() !== 'playlists') {
                    return null;
                }

                $built = match ($member) {
                    'addedBy' => BelongsTo::make('addedBy', 'users')->build(),
                    'curators' => HasMany::make('curators', 'users')->build(),
                    default => null,
                };

                return $built === null ? null : ($this->relations[$member] ??= $built);
            }
        };
    }

    /**
     * @return array<string, list<FieldInterface>>
     */
    private static function types(): array
    {
        static $types = null;

        return $types ??= [
            'albums' => [
                Id::make()->numeric()->build(),
                HasMany::make('tracks', 'tracks')->build(),
            ],
            'tracks' => [
                Id::make()->numeric()->build(),
                Str::make('title')->build(),
                BelongsToMany::make('playlists', 'playlists')
                    ->storedAs('playlist_links')
                    ->fields(
                        DateTime::make('addedAt')->readOnly(),
                        Integer::make('position'),
                        Str::make('note')->computedUsing(static fn(): string => 'n'),
                    )
                    ->build(),
                BelongsToMany::make('tags', 'tags')->build(),
                MorphToMany::make('favourites', ['albums', 'tracks'])
                    ->identifiedByRows(owner: 'track', type: 'type', id: 'favouriteId')->through('FavouriteRow')
                    ->fields(Integer::make('rank'))
                    ->build(),
            ],
            'playlists' => [
                Id::make()->numeric()->build(),
                Str::make('name')->build(),
            ],
            'tags' => [
                Id::make()->build(),
                Str::make('name')->build(),
            ],
            'users' => [
                Id::make()->encodeUsing(new ReversingIdEncoder())->build(),
                Str::make('name')->storedAs('display_name')->build(),
                BelongsToMany::make('playlists', 'playlists')->fields(Integer::make('position'))->build(),
            ],
            'shadows' => [
                Id::make()->build(),
                BelongsTo::make('pivot', 'users')->build(),
            ],
        ];
    }
}
