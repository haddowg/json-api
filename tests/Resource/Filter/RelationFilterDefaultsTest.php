<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\HasDefaultValue;
use haddowg\JsonApi\Resource\Filter\RelationFilters;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Serializer\RelationFilterDefaultsValidator;
use haddowg\JsonApi\Server\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation's `withoutFilterDefaults()`: declared on the builder, carried by the built
 * relation, applied to the related type's filters by {@see RelationFilters} and checked
 * at warm-up by {@see RelationFilterDefaultsValidator}.
 */
#[CoversClass(AbstractRelationBuilder::class)]
#[CoversClass(AbstractRelationValue::class)]
#[CoversClass(RelationFilters::class)]
#[CoversClass(RelationFilterDefaultsValidator::class)]
#[CoversClass(Where::class)]
#[CoversClass(WhereIn::class)]
#[CoversClass(WhereNotIn::class)]
#[CoversClass(WhereIdIn::class)]
#[CoversClass(WhereIdNotIn::class)]
final class RelationFilterDefaultsTest extends TestCase
{
    #[Test]
    public function aRelationDropsNoDefaultUnlessItSaysSo(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->build();

        self::assertFalse($relation->dropsAllFilterDefaults());
        self::assertSame([], $relation->droppedFilterDefaults());
    }

    #[Test]
    public function noKeysDropsEveryDefault(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build();

        self::assertTrue($relation->dropsAllFilterDefaults());
        self::assertSame([], $relation->droppedFilterDefaults());
    }

    #[Test]
    public function namedKeysAppendWithoutDuplicates(): void
    {
        $relation = HasMany::make('tracks', 'tracks')
            ->withoutFilterDefaults('explicit', 'status')
            ->withoutFilterDefaults('status', 'genre')
            ->build();

        self::assertFalse($relation->dropsAllFilterDefaults());
        self::assertSame(['explicit', 'status', 'genre'], $relation->droppedFilterDefaults());
    }

    #[Test]
    public function anEmptyKeyFailsTheDeclaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relation "tracks" names an empty filter key in withoutFilterDefaults().');

        HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit', '');
    }

    /**
     * @return iterable<string, array{FilterInterface&HasDefaultValue}>
     */
    public static function defaultedFilters(): iterable
    {
        yield 'where' => [Where::make('explicit', 'is_explicit', '!=')->asBoolean()->singular()->boolean()->describedAs('Explicit.')->example(true)->deprecated()->default(false)->build()];
        yield 'whereIn' => [WhereIn::make('tags', 'tag')->delimiter('|')->singular()->describedAs('Tags.')->default('new|hot')->build()];
        yield 'whereNotIn' => [WhereNotIn::make('tags', 'tag')->acceptsList()->default(['old'])->build()];
        yield 'whereIdIn' => [WhereIdIn::make()->delimiter(';')->default('1;2')->build()];
        yield 'whereIdNotIn' => [WhereIdNotIn::make('not', 'id')->deprecated()->default('3')->build()];
    }

    #[Test]
    #[DataProvider('defaultedFilters')]
    public function aBuiltInCopiesItselfWithoutItsDefault(FilterInterface&HasDefaultValue $filter): void
    {
        $relation = HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build();

        [$copy] = RelationFilters::relatedFilters($relation, [$filter]);

        self::assertInstanceOf($filter::class, $copy);
        self::assertInstanceOf(HasDefaultValue::class, $copy);
        self::assertFalse($copy->hasDefault());
        self::assertNull($copy->defaultValue());
        $expected = \get_object_vars($filter);
        $expected['default'] = null;
        $expected['hasDefault'] = false;
        self::assertEquals($expected, \get_object_vars($copy));
    }

    #[Test]
    public function aNamedKeyDropsOnlyThatDefault(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit')->build();

        $filters = RelationFilters::relatedFilters($relation, self::trackFilters());

        self::assertSame(['status' => 'published'], FilterDefaults::apply([], $filters));
    }

    #[Test]
    public function noKeysDropsEveryRelatedDefault(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build();

        self::assertSame([], FilterDefaults::apply([], RelationFilters::relatedFilters($relation, self::trackFilters())));
    }

    #[Test]
    public function aDroppedDefaultsFilterStillAppliesWhenRequested(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build();
        $filters = RelationFilters::relatedFilters($relation, self::trackFilters());

        $directives = FilterMatcher::directives(FilterDefaults::apply(['explicit' => 'true'], $filters), $filters);

        self::assertCount(1, $directives);
        self::assertSame('explicit', $directives[0]->filter->key());
        self::assertSame('true', $directives[0]->value);
    }

    #[Test]
    public function aRelationDroppingNothingReturnsTheFiltersUnchanged(): void
    {
        $filters = self::trackFilters();

        self::assertSame($filters, RelationFilters::relatedFilters(HasMany::make('tracks', 'tracks')->build(), $filters));
    }

    #[Test]
    public function theVocabularyKeepsADefaultTheRelationDeclaresItself(): void
    {
        $relation = HasMany::make('tracks', 'tracks')
            ->withoutFilterDefaults()
            ->withFilters(Where::make('status')->default('draft'))
            ->build();

        $vocabulary = RelationFilters::vocabulary($relation, self::trackFilters());

        self::assertSame(['explicit', 'title', 'status'], \array_map(static fn(FilterInterface $filter): string => $filter->key(), $vocabulary));
        self::assertSame(['status' => 'draft'], FilterDefaults::apply([], $vocabulary));
    }

    #[Test]
    public function theRelatedTypesOwnCollectionKeepsItsDefaults(): void
    {
        $server = self::server(FilterDefaultsAlbumResource::class);
        $tracks = $server->serializerFor('tracks');
        $albums = $server->serializerFor('albums');
        self::assertInstanceOf(AbstractResource::class, $tracks);
        self::assertInstanceOf(AbstractResource::class, $albums);

        RelationFilters::relatedFilters(self::relation($albums, 'cleanTracks'), $tracks->allFilters());

        self::assertSame(['explicit' => false, 'status' => 'published'], FilterDefaults::apply([], $tracks->allFilters()));
    }

    #[Test]
    public function dropsDefaultAnswersPerKey(): void
    {
        $named = HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit')->build();
        $all = HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build();

        self::assertTrue(RelationFilters::dropsDefault($named, 'explicit'));
        self::assertFalse(RelationFilters::dropsDefault($named, 'status'));
        self::assertTrue(RelationFilters::dropsDefault($all, 'status'));
        self::assertFalse(RelationFilters::dropsDefault(HasMany::make('tracks', 'tracks')->build(), 'explicit'));
    }

    #[Test]
    public function aFilterThatCannotDropItsDefaultFailsTheRead(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->withoutFilterDefaults('mood')->build();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "tracks" drops the default of filter "mood", but ' . FixedDefaultFilter::class . ' cannot drop its default.');

        RelationFilters::relatedFilters($relation, [new FixedDefaultFilter()]);
    }

    #[Test]
    public function theValidatorAcceptsKeysTheRelatedTypeDefaults(): void
    {
        $server = self::server(FilterDefaultsAlbumResource::class);

        (new RelationFilterDefaultsValidator($server))->validate('albums', $server->serializerFor('albums'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theValidatorRejectsAKeyTheRelatedTypeDoesNotFilterBy(): void
    {
        $server = self::server(UnknownKeyAlbumResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "tracks" on resource type "albums" declares withoutFilterDefaults() on "rating", which is not a filter declaring a default on its related type. Defaults are declared on: explicit, status.');

        (new RelationFilterDefaultsValidator($server))->validate('albums', $server->serializerFor('albums'));
    }

    #[Test]
    public function theValidatorRejectsAKeyWhoseFilterDeclaresNoDefault(): void
    {
        $server = self::server(UndefaultedKeyAlbumResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares withoutFilterDefaults() on "title", which is not a filter declaring a default on its related type.');

        (new RelationFilterDefaultsValidator($server))->validate('albums', $server->serializerFor('albums'));
    }

    #[Test]
    public function theValidatorRejectsADroppedDefaultThatCannotBeDropped(): void
    {
        $server = Server::make()->register(MoodTrackResource::class)->register(MoodAlbumResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "tracks" on resource type "albums" drops the default of filter "mood", but ' . FixedDefaultFilter::class . ' cannot drop its default.');

        (new RelationFilterDefaultsValidator($server))->validate('albums', $server->serializerFor('albums'));
    }

    #[Test]
    public function aPolymorphicRelationNeedsTheKeyDefaultedOnOneRelatedType(): void
    {
        $server = Server::make()
            ->register(FilterDefaultsTrackResource::class)
            ->register(FilterDefaultsVideoResource::class)
            ->register(PolymorphicFilterDefaultsResource::class);

        (new RelationFilterDefaultsValidator($server))->validate('playlists', $server->serializerFor('playlists'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aPolymorphicRelationRejectsAKeyNoRelatedTypeDefaults(): void
    {
        $server = Server::make()
            ->register(FilterDefaultsTrackResource::class)
            ->register(FilterDefaultsVideoResource::class)
            ->register(UnknownPolymorphicFilterDefaultsResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "items" on resource type "playlists" declares withoutFilterDefaults() on "title", which is not a filter declaring a default on any of its related types. Defaults are declared on: explicit, status, length.');

        (new RelationFilterDefaultsValidator($server))->validate('playlists', $server->serializerFor('playlists'));
    }

    #[Test]
    public function theValidatorSkipsAnUnregisteredRelatedType(): void
    {
        $server = Server::make()->register(UnknownKeyAlbumResource::class);

        (new RelationFilterDefaultsValidator($server))->validate('albums', $server->serializerFor('albums'));

        $this->addToAssertionCount(1);
    }

    /**
     * @return list<FilterInterface>
     */
    private static function trackFilters(): array
    {
        return (new FilterDefaultsTrackResource())->allFilters();
    }

    /**
     * @param class-string<AbstractResource> $album
     */
    private static function server(string $album): Server
    {
        return Server::make()->register(FilterDefaultsTrackResource::class)->register($album);
    }

    private static function relation(AbstractResource $resource, string $name): RelationInterface
    {
        return $resource->relationNamed($name) ?? throw new \LogicException('no relation ' . $name);
    }
}

final class FilterDefaultsTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [Id::make(), Str::make('title')];
    }

    public function filters(): array
    {
        return [
            Where::make('explicit')->asBoolean()->default(false),
            Where::make('title'),
            WhereIn::make('status')->default('published'),
        ];
    }
}

final class FilterDefaultsVideoResource extends AbstractResource
{
    public static string $type = 'videos';

    public function fields(): array
    {
        return [Id::make()];
    }

    public function filters(): array
    {
        return [Where::make('length', operator: '<')->default(600)];
    }
}

final class FilterDefaultsAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('tracks', 'tracks'),
            HasMany::make('cleanTracks', 'tracks')->withoutFilterDefaults('explicit'),
            HasMany::make('allTracks', 'tracks')->withoutFilterDefaults(),
            BelongsTo::make('single', 'tracks')->withoutFilterDefaults('status'),
        ];
    }
}

final class UnknownKeyAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit', 'rating')];
    }
}

final class UndefaultedKeyAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('tracks', 'tracks')->withoutFilterDefaults('title')];
    }
}

final class PolymorphicFilterDefaultsResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [Id::make(), MorphToMany::make('items', ['tracks', 'videos'])->withoutFilterDefaults('explicit', 'length')];
    }
}

final class UnknownPolymorphicFilterDefaultsResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [Id::make(), MorphToMany::make('items', ['tracks', 'videos'])->withoutFilterDefaults('title')];
    }
}

final class MoodTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [Id::make()];
    }

    public function filters(): array
    {
        return [new FixedDefaultFilter()];
    }
}

final class MoodAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('tracks', 'tracks')->withoutFilterDefaults()];
    }
}

final readonly class FixedDefaultFilter implements FilterInterface, HasDefaultValue
{
    public function key(): string
    {
        return 'mood';
    }

    public function constraints(): array
    {
        return [];
    }

    public function hasDefault(): bool
    {
        return true;
    }

    public function defaultValue(): mixed
    {
        return 'calm';
    }
}
