<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\MorphToManyBuilder;
use haddowg\JsonApi\Resource\Field\Positions;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\RelationOrderValidator;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `positionedBy()`: a stored integer position per member, read-only to clients, the
 * relation's default read order, checked at build or warm-up, and the positions a
 * persister writes.
 */
#[CoversClass(AbstractRelationBuilder::class)]
#[CoversClass(BelongsToManyBuilder::class)]
#[CoversClass(HasManyBuilder::class)]
#[CoversClass(MorphToManyBuilder::class)]
#[CoversClass(Positions::class)]
#[CoversClass(RelationSorts::class)]
#[CoversClass(RelationOrderValidator::class)]
#[CoversClass(FieldState::class)]
final class PositionedByTest extends TestCase
{
    #[Test]
    public function aPivotPositionBecomesReadOnlyToEveryWrite(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')
            ->fields(Integer::make('position'), Str::make('note'))
            ->positionedBy('position')
            ->build();

        $position = $relation->pivotField('position');
        self::assertInstanceOf(Integer::class, $position);
        self::assertTrue($position->isReadOnly(true));
        self::assertTrue($position->isReadOnly(false));
        self::assertSame(['note'], self::names($relation->writablePivotFields(true)));
        self::assertSame(['note'], self::names($relation->writablePivotFields(false)));
        self::assertSame(['position', 'note'], self::names($relation->readablePivotFields()));
        self::assertSame('position', $relation->positionField());
    }

    /**
     * @return iterable<string, array{AbstractRelationBuilder, string}>
     */
    public static function badPivotPositions(): iterable
    {
        yield 'missing' => [BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('rank'))->positionedBy('position'), 'it declares no such pivot field'];
        yield 'not an integer' => [BelongsToMany::make('tracks', 'tracks')->fields(Str::make('position'))->positionedBy('position'), 'it is not an Integer field'];
        yield 'computed' => [BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->computed())->positionedBy('position'), 'it is computed, not stored'];
        yield 'morphToMany missing' => [MorphToMany::make('items', ['tracks'])->identifiedBy(static fn(): array => [])->positionedBy('position'), 'it declares no such pivot field'];
    }

    #[Test]
    #[DataProvider('badPivotPositions')]
    public function aPivotPositionThatIsNoStoredIntegerFieldFailsTheBuild(AbstractRelationBuilder $builder, string $problem): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is positioned by "position", but ' . $problem . '.');

        $builder->build();
    }

    #[Test]
    public function thePositionIsTheDefaultReadOrderUnlessADefaultSortIsDeclared(): void
    {
        $server = self::server();
        $positioned = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->storedAs('pos'))->positionedBy('position')->build();
        $sorted = BelongsToMany::make('tracks', 'tracks')
            ->fields(Integer::make('position'))
            ->positionedBy('position')
            ->defaultSort(new SortDirective(SortByField::make('title'), true))
            ->build();

        $default = RelationSorts::defaultFor($positioned, $server);
        self::assertCount(1, $default);
        self::assertFalse($default[0]->descending);
        self::assertEquals(SortByField::make('position', 'pos'), $default[0]->sort);
        self::assertSame([], $positioned->defaultSort());
        self::assertSame(['title'], \array_map(static fn(SortDirective $directive): string => $directive->sort->key(), RelationSorts::defaultFor($sorted, $server)));
    }

    #[Test]
    public function aHasManyPositionReadsTheRelatedFieldsColumn(): void
    {
        $relation = HasMany::make('tracks', 'tracks')->positionedBy('trackNumber')->build();

        self::assertEquals([new SortDirective(SortByField::make('trackNumber', 'track_no'), false)], RelationSorts::defaultFor($relation, self::server()));
    }

    #[Test]
    public function anIdentifiedMorphToManyRendersAndQueriesInPositionOrder(): void
    {
        $server = self::server(PositionedOwnerResource::class);
        $owner = (object) ['id' => '1', 'rows' => [
            (object) ['type' => 'tracks', 'key' => 1, 'position' => 2],
            (object) ['type' => 'tracks', 'key' => 2, 'position' => 0],
            (object) ['type' => 'tracks', 'key' => 3, 'position' => 1],
        ]];

        $response = DataResponse::fromResource($owner, $server->serializerFor('owners'))->toPsrResponse($server, new ServerRequest('GET', '/owners/1'));
        /** @var array{data: array{relationships: array{items: array{data: list<array{id: string}>}}}} $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['2', '3', '1'], \array_column($body['data']['relationships']['items']['data'], 'id'));

        $relation = (new PositionedOwnerResource())->relationNamed('items');
        self::assertInstanceOf(MorphToMany::class, $relation);
        self::assertEquals([new SortDirective(SortByField::make('position'), false)], LinkRowQuery::resolve($relation, request: new JsonApiRequest(new ServerRequest('GET', '/')))->sorts);
    }

    #[Test]
    public function theValidatorAcceptsAStoredReadOnlyIntegerOnTheRelatedType(): void
    {
        $server = self::server(PositionedAlbumResource::class);

        (new RelationOrderValidator($server))->validate('albums', $server->serializerFor('albums'));

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badRelatedPositions(): iterable
    {
        yield 'missing' => ['missing', 'it declares no such field'];
        yield 'not an integer' => ['title', 'it is not an Integer field'];
        yield 'computed' => ['computedNumber', 'it is computed, not stored'];
        yield 'writable' => ['writableNumber', 'it is writable; declare it ->readOnly()'];
    }

    #[Test]
    #[DataProvider('badRelatedPositions')]
    public function theValidatorRejectsARelatedPositionThatIsNoStoredReadOnlyInteger(string $field, string $problem): void
    {
        BadPositionAlbumResource::$field = $field;
        $server = self::server(BadPositionAlbumResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "tracks" on resource type "albums" is positioned by "' . $field . '" on type "tracks", but ' . $problem);

        (new RelationOrderValidator($server))->validate('albums', $server->serializerFor('albums'));
    }

    #[Test]
    public function aReplaceWritesEachMembersIndex(): void
    {
        self::assertSame(['a' => 0, 'b' => 1, 'c' => 2], Positions::forReplace(['a', 'b', 'a', 'c']));
    }

    #[Test]
    public function anAddAppendsNewMembersAfterTheHighestPosition(): void
    {
        self::assertSame(['d' => 8, 'e' => 9], Positions::forAdd(['a' => 0, 'b' => 7], ['b', 'd', 'e', 'd']));
        self::assertSame(['a' => 0], Positions::forAdd([], ['a']));
    }

    #[Test]
    public function aPlanMovesRowsPastEveryPositionInUseBeforeTheirFinalOne(): void
    {
        $plan = Positions::plan(['a' => 0, 'b' => 1, 'c' => 2, 'x' => 5], Positions::forReplace(['c', 'b', 'a', 'n']));

        self::assertSame(['x'], $plan->removed);
        self::assertSame(['c' => 6, 'a' => 8], $plan->temporary);
        self::assertSame(['c' => 0, 'a' => 2, 'n' => 3], $plan->final);
    }

    #[Test]
    public function aRemoveMovesNothingAndLeavesGaps(): void
    {
        $plan = Positions::plan(['a' => 0, 'b' => 1, 'c' => 2], ['a' => 0, 'c' => 2]);

        self::assertSame(['b'], $plan->removed);
        self::assertSame([], $plan->temporary);
        self::assertSame([], $plan->final);
    }

    #[Test]
    public function theProjectionShowsThePositionAsAnyReadOnlyPivotField(): void
    {
        $positioned = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position'), Str::make('note'))->positionedBy('position')->build();
        $declared = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->readOnly(), Str::make('note'))->build();

        $document = self::project($positioned->pivotFields());

        self::assertSame($document, self::project($declared->pivotFields()));
        self::assertSame(['note'], \array_keys(self::properties($document, 'AlbumsTracksWritePivot')));
        self::assertSame(['position', 'note'], \array_keys(self::properties($document, 'AlbumsTracksPivot')));
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private static function properties(array $document, string $component): array
    {
        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $schema = $schemas[$component] ?? null;
        self::assertIsArray($schema);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);

        return $properties;
    }

    /**
     * @param list<FieldInterface> $pivotFields
     *
     * @return array<array-key, mixed>
     */
    private static function project(array $pivotFields): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'albums', fields: [Id::make()->example('alb-1')->build()], relations: [FakeRelationMetadata::toMany('tracks', ['tracks'], pivotFields: $pivotFields)]),
                FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make()->example('trk-1')->build()]),
            ],
        ))->toArray();
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<string>
     */
    private static function names(array $fields): array
    {
        return \array_map(static fn(FieldInterface $field): string => $field->name(), $fields);
    }

    /**
     * @param class-string<AbstractResource> $owner
     */
    private static function server(?string $owner = null): Server
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()->register(PositionedTrackResource::class)->withPsr17($psr17, $psr17);

        return $owner === null ? $server : $server->register($owner);
    }
}

final class PositionedTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Integer::make('trackNumber')->storedAs('track_no')->readOnly(),
            Integer::make('computedNumber')->computed()->readOnly(),
            Integer::make('writableNumber'),
        ];
    }
}

final class PositionedAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('tracks', 'tracks')->positionedBy('trackNumber')];
    }
}

final class BadPositionAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public static string $field = 'missing';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('tracks', 'tracks')->positionedBy(self::$field)];
    }
}

final class PositionedOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphToMany::make('items', ['tracks'])
                ->identifiedBy(self::linkRows(...))
                ->fields(Integer::make('position'))
                ->positionedBy('position')
                ->withData(),
        ];
    }

    /**
     * @return list<array{0: string, 1: string|int, 2: \stdClass}>
     */
    public static function linkRows(mixed $owner): array
    {
        $rows = [];
        foreach ($owner instanceof \stdClass && \is_array($owner->rows) ? $owner->rows : [] as $row) {
            if ($row instanceof \stdClass && \is_string($row->type) && \is_int($row->key)) {
                $rows[] = [$row->type, $row->key, $row];
            }
        }

        return $rows;
    }
}
