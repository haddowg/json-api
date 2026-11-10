<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\LinkRowFields;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\MorphToManyBuilder;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `MorphToMany` declared with `identifiedByRows()`: core derives its members from the
 * link rows it names, and its link-row query carries the fields, the narrowing and the
 * tiebreak a data layer runs, which the in-memory query applies the same way.
 */
#[CoversClass(LinkRowFields::class)]
#[CoversClass(MorphToMany::class)]
#[CoversClass(MorphToManyBuilder::class)]
#[CoversClass(LinkRowQuery::class)]
#[CoversClass(InMemoryLinkRows::class)]
final class MorphToManyLinkRowFieldsTest extends TestCase
{
    #[Test]
    public function membersComeFromTheRowsMappedToTheirTypes(): void
    {
        $members = self::relation()->identifiedMembers(self::owner(), self::request());

        self::assertSame([['agencies', 7], ['advertisers', 3], ['agencies', '2']], self::pairs($members));
        self::assertSame(2, self::rowOf($members[0])->position);
    }

    #[Test]
    public function rowsThatNameNoDeclaredTypeOrNoIdAreNotMembers(): void
    {
        $owner = self::owner();
        $owner->links[] = (object) ['kind' => 'publisher', 'key' => 9, 'position' => 4];
        $owner->links[] = (object) ['kind' => 'agency', 'key' => '', 'position' => 5];
        $owner->links[] = (object) ['kind' => 'agencies', 'key' => 8, 'position' => 6];
        $owner->links[] = (object) ['kind' => 'agency', 'key' => 7, 'position' => 7];

        self::assertSame([['agencies', 7], ['advertisers', 3], ['agencies', '2']], self::pairs(self::relation()->identifiedMembers($owner, self::request())));
    }

    #[Test]
    public function withoutAMapTheStoredTypeIsTheJsonApiType(): void
    {
        $relation = MorphToMany::make('companies', ['agencies', 'advertisers'])
            ->storedAs('links')
            ->through(\stdClass::class)
            ->identifiedByRows(owner: 'owner', type: 'kind', id: 'key')
            ->build();
        $owner = (object) ['links' => [(object) ['kind' => 'agencies', 'key' => 1], (object) ['kind' => 'agency', 'key' => 2]]];

        self::assertSame([['agencies', 1]], self::pairs($relation->identifiedMembers($owner, self::request())));
        self::assertTrue($relation->storesIdentifiers());
    }

    #[Test]
    public function theLinkageRendersFromTheRowsTiesBrokenByStoredTypeThenId(): void
    {
        $data = $this->linkage(self::owner(), RowsOwnerResource::class);

        self::assertSame([
            ['type' => 'advertisers', 'id' => '3', 'meta' => ['pivot' => ['position' => 1]]],
            ['type' => 'agencies', 'id' => '2', 'meta' => ['pivot' => ['position' => 3]]],
            ['type' => 'agencies', 'id' => '7', 'meta' => ['pivot' => ['position' => 2]]],
        ], $data);
    }

    #[Test]
    public function aPositionedRelationRendersInPositionOrder(): void
    {
        $data = $this->linkage(self::owner(), PositionedRowsOwnerResource::class);

        self::assertSame(['3', '7', '2'], \array_column($data, 'id'));
    }

    #[Test]
    public function theQueryCarriesTheDeclaredFieldsAndTiebreak(): void
    {
        $relation = self::relation();
        $query = LinkRowQuery::resolve($relation);

        self::assertSame($relation->linkRowFields(), $query->fields);
        self::assertSame(['kind', 'key'], $query->tiebreak());
        self::assertSame(['agency', 'advertiser'], $query->typeValues());
        self::assertSame([], $query->admittedIds());

        $fields = $query->fields;
        self::assertNotNull($fields);
        self::assertSame(\stdClass::class, $fields->through);
        self::assertSame('owner', $fields->owner);
        self::assertSame(['agency' => 'agencies', 'advertiser' => 'advertisers'], $fields->typeMap);
    }

    #[Test]
    public function narrowingATypeMovesItsStoredValuesToTheAdmittedIds(): void
    {
        $query = LinkRowQuery::resolve(self::relation())->withAdmitted('agencies', [7]);

        self::assertSame(['advertiser'], $query->typeValues());
        self::assertSame(['agency' => [7]], $query->admittedIds());
        self::assertNotNull($query->member((object) ['kind' => 'agency', 'key' => '7']));
        self::assertNull($query->member((object) ['kind' => 'agency', 'key' => 2]));
        self::assertNull($query->member((object) ['kind' => 'publisher', 'key' => 1]));
        self::assertSame([], LinkRowQuery::resolve(self::relation())->withAdmitted('agencies', [])->admittedIds());
    }

    #[Test]
    public function theKeysAreEachParentsDistinctStorageKeysPerRequestedType(): void
    {
        $owner = self::owner();
        self::assertIsArray($owner->links);
        $owner->links[] = (object) ['kind' => 'agency', 'key' => 7, 'position' => 9];
        $other = (object) ['links' => [(object) ['kind' => 'advertiser', 'key' => 8, 'position' => 0]]];

        self::assertSame([['agencies' => [7, '2']]], (new InMemoryLinkRows())->linkRowKeys([$owner], self::relation(), ['agencies'], self::request()));
        self::assertSame(
            [['advertisers' => [3], 'agencies' => [7, '2']], ['advertisers' => [8]], []],
            (new InMemoryLinkRows())->linkRowKeys([$owner, $other, (object) ['links' => []]], self::relation(), ['advertisers', 'agencies'], self::request()),
        );
    }

    #[Test]
    public function narrowedAsksEachGuardedTypeOnceAcrossTheParents(): void
    {
        $relation = MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
            ->storedAs('links')
            ->through(\stdClass::class)
            ->identifiedByRows(owner: 'owner', type: 'kind', id: 'key', typeMap: ['agency' => 'agencies', 'advertiser' => 'advertisers', 'publisher' => 'publishers'])
            ->fields(Integer::make('position'))
            ->build();
        $owner = self::owner();
        $other = (object) ['links' => [(object) ['kind' => 'agency', 'key' => 5, 'position' => 0], (object) ['kind' => 'agency', 'key' => 7, 'position' => 1]]];
        $asked = [];

        $queries = LinkRowQuery::resolve($relation)->narrowed(
            [$owner, $other],
            $relation,
            new InMemoryLinkRows(),
            ['agencies', 'publishers'],
            static function (string $type, array $ids) use (&$asked): array {
                $asked[] = [$type, $ids];

                return [2, 5];
            },
            self::request(),
        );

        self::assertSame([['agencies', [7, '2', 5]]], $asked);
        self::assertCount(2, $queries);
        self::assertSame(['agencies' => ['2'], 'publishers' => []], $queries[0]->admitted);
        self::assertSame(['agencies' => [5], 'publishers' => []], $queries[1]->admitted);
        self::assertSame(['advertiser'], $queries[0]->typeValues());
        self::assertSame([['advertisers', 3], ['agencies', '2']], self::pairs((new InMemoryLinkRows())->queryLinkRows($owner, $relation, $queries[0], self::request())->items));
        self::assertSame([['agencies', 5]], self::pairs((new InMemoryLinkRows())->queryLinkRows($other, $relation, $queries[1], self::request())->items));
    }

    #[Test]
    public function withNothingGuardedEachParentKeepsTheQuery(): void
    {
        $query = LinkRowQuery::resolve(self::relation());

        self::assertSame([$query, $query], $query->narrowed([self::owner(), self::owner()], self::relation(), new InMemoryLinkRows(), [], static fn(): array => [], self::request()));
    }

    #[Test]
    public function narrowingAppliesBeforeThePageAndTheCount(): void
    {
        $relation = self::relation();
        $query = LinkRowQuery::resolve($relation, ['position'], window: new OffsetWindow(0, 1), countable: true)
            ->withAdmitted('advertisers', []);

        $page = (new InMemoryLinkRows())->queryLinkRows(self::owner(), $relation, $query, self::request());

        self::assertSame(2, $page->total);
        self::assertSame([['agencies', 7]], self::pairs($page->items));
    }

    #[Test]
    public function tiesTheSortsLeaveBreakByStoredTypeThenId(): void
    {
        $relation = self::relation();
        $owner = (object) ['links' => [
            (object) ['kind' => 'agency', 'key' => 9, 'position' => 1],
            (object) ['kind' => 'agency', 'key' => 4, 'position' => 1],
            (object) ['kind' => 'advertiser', 'key' => 5, 'position' => 1],
            (object) ['kind' => 'agency', 'key' => 1, 'position' => 0],
        ]];

        $page = (new InMemoryLinkRows())->queryLinkRows($owner, $relation, LinkRowQuery::resolve($relation, ['position']), self::request());

        self::assertSame([['agencies', 1], ['advertisers', 5], ['agencies', 4], ['agencies', 9]], self::pairs($page->items));
    }

    #[Test]
    public function aClosureRelationNarrowsTooButKeepsTheClosureOrder(): void
    {
        $relation = MorphToMany::make('companies', ['agencies', 'advertisers'])
            ->identifiedBy(static fn(): array => [['agencies', 7], ['advertisers', 3], ['agencies', 2]])
            ->build();
        $query = LinkRowQuery::resolve($relation)->withAdmitted('agencies', ['2']);

        self::assertNull($query->fields);
        self::assertSame([], $query->tiebreak());
        self::assertSame([['advertisers', 3], ['agencies', 2]], self::pairs((new InMemoryLinkRows())->queryLinkRows(new \stdClass(), $relation, $query, self::request())->items));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('its relation was declared with identifiedBy()');
        $query->typeValues();
    }

    #[Test]
    public function aClosureRelationThatQueriesItsRowsSaysTheyAreServedInMemory(): void
    {
        $closure = static fn(): array => [];
        $pivoted = MorphToMany::make('companies', ['agencies'])->identifiedBy($closure)->fields(Integer::make('position'))->build();
        $plain = MorphToMany::make('companies', ['agencies'])->identifiedBy($closure)->build();

        self::assertStringContainsString('filter, sort and page them in memory', LinkRowQuery::inMemoryNotice($pivoted) ?? '');
        self::assertNull(LinkRowQuery::inMemoryNotice($plain));
        self::assertNotNull(LinkRowQuery::inMemoryNotice($plain, new PagePaginator()));
        self::assertNull(LinkRowQuery::inMemoryNotice(self::relation(), new PagePaginator()));
        self::assertNull(LinkRowQuery::inMemoryNotice(MorphToMany::make('companies', ['agencies'])->build()));
    }

    #[Test]
    public function rowsThatAreNotIterableAreALogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('it must be iterable');

        self::relation()->identifiedMembers((object) ['links' => 'nope'], self::request());
    }

    #[Test]
    public function bothDeclarationsFailTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares both identifiedBy() and identifiedByRows()');

        self::builder()->identifiedBy(static fn(): array => [])->build();
    }

    #[Test]
    public function anUnnamedFieldFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares identifiedByRows() with a type "", which is not a field name');

        self::builder()->identifiedByRows(owner: 'owner', type: '', id: 'key')->build();
    }

    #[Test]
    public function aPathIsNotAFieldOfTheRow(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('with an owner "owner.id", which is not a field name');

        self::builder()->identifiedByRows(owner: 'owner.id', type: 'kind', id: 'key')->build();
    }

    #[Test]
    public function oneFieldInTwoRolesFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('with the same field twice');

        self::builder()->identifiedByRows(owner: 'owner', type: 'key', id: 'key')->build();
    }

    #[Test]
    public function rowsWithoutThroughFailTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares identifiedByRows() but no through()');

        self::builder()->through(null)->build();
    }

    #[Test]
    public function aMapToAnUndeclaredTypeFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('maps the stored type "publisher" to "publishers", which it does not declare');

        self::builder()->identifiedByRows(owner: 'owner', type: 'kind', id: 'key', typeMap: ['publisher' => 'publishers'])->build();
    }

    #[Test]
    public function aPivotFieldOffTheRowFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares the pivot field "label" stored as "meta.label", which is not a field of its link row');

        self::builder()->fields(Str::make('label')->storedAs('meta.label'))->build();
    }

    #[Test]
    public function aPivotFilterOffTheRowFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares the filter "label" on "pivot.meta.label", which is not a field of its link row');

        self::builder()->withFilters(Where::make('label', 'pivot.meta.label'))->build();
    }

    #[Test]
    public function aPositionOnALinkingFieldFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is positioned by "rank", which is the field that links its rows');

        self::builder()->fields(Integer::make('rank')->storedAs('key'))->positionedBy('rank')->build();
    }

    #[Test]
    public function pivotFieldsNeedOneDeclarationOrTheOther(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be declared with identifiedBy() or identifiedByRows()');

        MorphToMany::make('companies', ['agencies'])->fields(Integer::make('position'))->build();
    }

    public static function builder(): MorphToManyBuilder
    {
        return MorphToMany::make('companies', ['agencies', 'advertisers'])
            ->storedAs('links')
            ->through(\stdClass::class)
            ->identifiedByRows(owner: 'owner', type: 'kind', id: 'key', typeMap: ['agency' => 'agencies', 'advertiser' => 'advertisers'])
            ->fields(Integer::make('position'));
    }

    private static function relation(): MorphToMany
    {
        return self::builder()->build();
    }

    private static function owner(): \stdClass
    {
        return (object) ['id' => '1', 'links' => [
            (object) ['kind' => 'agency', 'key' => 7, 'position' => 2],
            (object) ['kind' => 'advertiser', 'key' => 3, 'position' => 1],
            (object) ['kind' => 'agency', 'key' => '2', 'position' => 3],
        ]];
    }

    private static function rowOf(IdentifiedMember $member): \stdClass
    {
        self::assertInstanceOf(\stdClass::class, $member->row);

        return $member->row;
    }

    /**
     * @param iterable<mixed> $members
     *
     * @return list<array{0: string, 1: string|int}>
     */
    private static function pairs(iterable $members): array
    {
        $pairs = [];
        foreach ($members as $member) {
            self::assertInstanceOf(IdentifiedMember::class, $member);
            $pairs[] = [$member->type, $member->id];
        }

        return $pairs;
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('GET', '/owners/1'));
    }

    /**
     * @param class-string<AbstractResource> $owner
     *
     * @return list<array{type: string, id: string, meta?: array<string, mixed>}>
     */
    private function linkage(\stdClass $model, string $owner): array
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->register($owner)
            ->register(RowsAgencyResource::class)
            ->register(RowsAdvertiserResource::class)
            ->withPsr17($psr17, $psr17);
        $response = DataResponse::fromResource($model, $server->serializerFor('owners'))->toPsrResponse($server, new ServerRequest('GET', '/owners/1'));

        /** @var array{data: array{relationships: array{companies: array{data: list<array{type: string, id: string, meta?: array<string, mixed>}>}}}} $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['data']['relationships']['companies']['data'];
    }
}

final class RowsOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphToManyLinkRowFieldsTest::builder()->withData(),
        ];
    }
}

final class PositionedRowsOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphToManyLinkRowFieldsTest::builder()->positionedBy('position')->withData(),
        ];
    }
}

final class RowsAgencyResource extends AbstractResource
{
    public static string $type = 'agencies';

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class RowsAdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function fields(): array
    {
        return [Id::make()];
    }
}
