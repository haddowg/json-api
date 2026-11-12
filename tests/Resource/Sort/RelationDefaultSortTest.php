<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Sort;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApi\Serializer\RelationOrderValidator;
use haddowg\JsonApi\Server\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A to-many relation's `defaultSort()`: declared on the builder, carried by the built
 * relation, checked against the relation's sort vocabulary at build and warm-up, and
 * resolved for a data layer by {@see RelationSorts::defaultFor()}.
 */
#[CoversClass(AbstractRelationBuilder::class)]
#[CoversClass(AbstractRelationValue::class)]
#[CoversClass(RelationSorts::class)]
#[CoversClass(RelationOrderValidator::class)]
#[CoversClass(SortMatcher::class)]
final class RelationDefaultSortTest extends TestCase
{
    #[Test]
    public function aToManyCarriesItsDeclaredDefaultInOrder(): void
    {
        $directives = [new SortDirective(SortByField::make('position'), false), new SortDirective(SortByField::make('title'), true)];

        $relation = HasMany::make('tracks', 'tracks')->defaultSort(...$directives)->build();

        self::assertSame($directives, $relation->defaultSort());
    }

    #[Test]
    public function aLaterDeclarationReplacesAnEarlierOne(): void
    {
        $title = new SortDirective(SortByField::make('title'), false);

        $relation = BelongsToMany::make('tags', 'tags')
            ->defaultSort(new SortDirective(SortByField::make('position'), false))
            ->defaultSort($title)
            ->build();

        self::assertSame([$title], $relation->defaultSort());
    }

    #[Test]
    public function aRelationWithoutOneDeclaresNone(): void
    {
        self::assertSame([], HasMany::make('tracks', 'tracks')->build()->defaultSort());
        self::assertSame([], BelongsTo::make('album', 'albums')->build()->defaultSort());
    }

    /**
     * @return iterable<string, array{AbstractRelationBuilder}>
     */
    public static function toOnes(): iterable
    {
        yield 'belongsTo' => [BelongsTo::make('album', 'albums')];
        yield 'hasOne' => [HasOne::make('cover', 'images')];
        yield 'morphTo' => [MorphTo::make('subject', ['albums', 'tracks'])];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('toOnes')]
    public function aToOneDeclaringADefaultFailsTheBuild(AbstractRelationBuilder $builder): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is a to-one');

        $builder->defaultSort(new SortDirective(SortByField::make('title'), false))->build();
    }

    #[Test]
    public function aPolymorphicToManyDeclaringADefaultFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a polymorphic relation not declared with identifiedBy() takes no sort');

        MorphToMany::make('items', ['albums', 'tracks'])->defaultSort(new SortDirective(SortByField::make('title'), false))->build();
    }

    #[Test]
    public function theRelationsDefaultReplacesTheRelatedTypesDefault(): void
    {
        $server = self::server();
        $album = $server->serializerFor('albums');
        self::assertInstanceOf(AbstractResource::class, $album);

        self::assertSame(['position'], self::keys(RelationSorts::defaultFor(self::relation($album, 'tracks'), $server)));
        self::assertSame(['title'], self::keys(RelationSorts::defaultFor(self::relation($album, 'bonusTracks'), $server)));
    }

    #[Test]
    public function aPolymorphicOrToOneRelationHasNoDefault(): void
    {
        $server = self::server();

        self::assertSame([], RelationSorts::defaultFor(MorphToMany::make('items', ['tracks'])->build(), $server));
        self::assertSame([], RelationSorts::defaultFor(BelongsTo::make('track', 'tracks')->build(), $server));
    }

    #[Test]
    public function theVocabularyMergesTheRelatedSortsTheRelationsSortsAndItsPivotFields(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')
            ->fields(Integer::make('position'), Str::make('note')->storedAs('memo'))
            ->withSorts(SortByField::make('length'))
            ->build();

        $vocabulary = RelationSorts::vocabulary($relation, self::server());

        self::assertSame(['title', 'byTitle.', 'length', 'position', 'note'], \array_map(
            static fn(SortInterface $sort): string => $sort instanceof SortFamily ? $sort->key() . '.' : $sort->key(),
            $vocabulary,
        ));
        $note = \array_values(\array_filter($vocabulary, static fn(SortInterface $sort): bool => $sort->key() === 'note'))[0];
        self::assertInstanceOf(SortByField::class, $note);
        self::assertSame('memo', $note->column);
        self::assertSame([], RelationSorts::vocabulary(MorphToMany::make('items', ['tracks'])->build(), self::server()));
    }

    #[Test]
    public function theValidatorAcceptsEveryKeyTheVocabularyAnswers(): void
    {
        $server = self::server();

        (new RelationOrderValidator($server))->validate('albums', $server->serializerFor('albums'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theValidatorRejectsAKeyTheRelationDoesNotAccept(): void
    {
        $server = Server::make()->register(DefaultSortTrackResource::class)->register(UnknownDefaultSortAlbumResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "tracks" on resource type "albums" declares a defaultSort() on "rating", which is not a sort the relation accepts. It accepts: title, byTitle.*, length.');

        (new RelationOrderValidator($server))->validate('albums', $server->serializerFor('albums'));
    }

    #[Test]
    public function theMatcherRecognisesExactKeysAndFamilyKeysWithoutResolvingThem(): void
    {
        $sorts = [SortByField::make('title'), SortFamily::make('meta', static fn(): never => throw new \LogicException('resolved'))];

        self::assertTrue(SortMatcher::recognises($sorts, 'title'));
        self::assertTrue(SortMatcher::recognises($sorts, 'meta.rank'));
        self::assertFalse(SortMatcher::recognises($sorts, 'meta'));
        self::assertFalse(SortMatcher::recognises($sorts, 'rank'));
    }

    private static function server(): Server
    {
        return Server::make()->register(DefaultSortTrackResource::class)->register(DefaultSortAlbumResource::class);
    }

    private static function relation(AbstractResource $resource, string $name): \haddowg\JsonApi\Resource\Field\RelationInterface
    {
        return $resource->relationNamed($name) ?? throw new \LogicException('no relation ' . $name);
    }

    /**
     * @param list<SortDirective> $directives
     *
     * @return list<string>
     */
    private static function keys(array $directives): array
    {
        return \array_map(static fn(SortDirective $directive): string => $directive->sort->key(), $directives);
    }

    #[Test]
    public function aPivotSortCarriesItsFieldsDescription(): void
    {
        $relation = BelongsToMany::make('tags', 'tags')
            ->fields(Integer::make('weight')->describedAs('How strongly the tag applies.'), Str::make('note'))
            ->build();

        $descriptions = [];
        foreach (RelationSorts::pivotSorts($relation) as $sort) {
            self::assertInstanceOf(SortByField::class, $sort);
            $descriptions[$sort->key()] = $sort->getDescription();
        }

        self::assertSame(['weight' => 'How strongly the tag applies.', 'note' => null], $descriptions);
    }
}

final class DefaultSortTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [Id::make(), Str::make('title')->sortable()];
    }

    public function sorts(): array
    {
        return [SortFamily::make('byTitle', static fn(string $suffix, JsonApiRequestInterface $request): SortByField => SortByField::make('byTitle.' . $suffix, 'title'))];
    }

    public function defaultSort(): array
    {
        return [new SortDirective(SortByField::make('title'), false)];
    }
}

final class DefaultSortAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('tracks', 'tracks')
                ->fields(Integer::make('position'))
                ->defaultSort(new SortDirective(SortByField::make('position'), false)),
            HasMany::make('bonusTracks', 'tracks'),
            HasMany::make('byTitle', 'tracks')
                ->withSorts(SortByField::make('length'))
                ->defaultSort(new SortDirective(SortByField::make('byTitle.en', 'title'), true), new SortDirective(SortByField::make('length'), false)),
        ];
    }
}

final class UnknownDefaultSortAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('tracks', 'tracks')
                ->withSorts(SortByField::make('length'))
                ->defaultSort(new SortDirective(SortByField::make('rating'), false)),
        ];
    }
}
