<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\Internal\LinkRowCarrier;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\MorphToManyBuilder;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pivot fields on a `MorphToMany` declared with `identifiedBy()`: read off the link rows
 * the closure returns, rendered as `meta.pivot` beside any identifier meta, and the rows
 * filtered, sorted and paged through a {@see LinkRowQuery}.
 */
#[CoversClass(MorphToMany::class)]
#[CoversClass(MorphToManyBuilder::class)]
#[CoversClass(LinkRowQuery::class)]
#[CoversClass(InMemoryLinkRows::class)]
#[CoversClass(LinkRowCarrier::class)]
#[CoversClass(IdentifiedMember::class)]
#[CoversClass(RelationSorts::class)]
final class MorphToManyPivotTest extends TestCase
{
    #[Test]
    public function eachIdentifierCarriesItsReadablePivotValuesCastFromItsRow(): void
    {
        $data = $this->linkage(self::owner());

        self::assertSame([
            ['type' => 'agencies', 'id' => '7', 'meta' => ['pivot' => ['position' => 2, 'linkedAt' => '2026-01-02T03:04:05+00:00', 'label' => 'unloaded']]],
            ['type' => 'advertisers', 'id' => '3', 'meta' => ['pivot' => ['position' => 1, 'linkedAt' => null, 'label' => 'unloaded']]],
            ['type' => 'agencies', 'id' => '2', 'meta' => ['pivot' => ['position' => 3, 'linkedAt' => null, 'label' => 'unloaded']]],
        ], $data);
    }

    #[Test]
    public function theDefaultSortOrdersTheLinkageByTheRows(): void
    {
        $data = $this->linkage(self::owner(), SortedOwnerResource::class);

        self::assertSame(['3', '7', '2'], \array_column($data, 'id'));
    }

    #[Test]
    public function identifierMetaSitsBesidePivotAndReceivesTheMember(): void
    {
        $data = $this->linkage(self::owner(), MetaOwnerResource::class);

        self::assertSame(['pivot' => ['position' => 2], 'via' => 'agencies:7:row'], $data[0]['meta'] ?? null);
    }

    #[Test]
    public function hostSuppliedMembersRenderThePivotOfTheirRows(): void
    {
        $seam = new class implements RelationshipLinkageInterface {
            public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): RelationshipLinkage
            {
                return new RelationshipLinkage([IdentifiedMember::of(['advertisers', 3], row: (object) ['position' => 9])]);
            }
        };

        $data = $this->linkage(self::owner(), seam: $seam);

        self::assertSame([['type' => 'advertisers', 'id' => '3', 'meta' => ['pivot' => ['position' => 9, 'linkedAt' => null, 'label' => 'unloaded']]]], $data);
    }

    #[Test]
    public function aMemberWithoutTheRowItsStoredPivotFieldsReadIsALogicException(): void
    {
        $relation = MorphToMany::make('companies', ['agencies'])
            ->identifiedBy(static fn(): array => [['agencies', 1]])
            ->fields(Integer::make('position'))
            ->build();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares stored pivot fields, so each member must carry the link row');

        $relation->identifiedMembers(new \stdClass(), self::request());
    }

    #[Test]
    public function onlyComputedPivotFieldsNeedNoRow(): void
    {
        $relation = MorphToMany::make('companies', ['agencies'])
            ->identifiedBy(static fn(): array => [['agencies', 1]])
            ->fields(Str::make('label')->computedUsing(static fn(mixed $edge): string => 'computed'))
            ->build();

        $members = $relation->identifiedMembers(new \stdClass(), self::request());

        self::assertSame(['label' => 'computed'], $relation->pivotValues($members[0], new \stdClass(), self::request()));
    }

    #[Test]
    public function pivotFieldsWithoutIdentifiedByFailTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares pivot fields, so it must be declared with identifiedBy()');

        MorphToMany::make('companies', ['agencies'])->fields(Integer::make('position'))->build();
    }

    #[Test]
    public function aDefaultSortOnAFieldThatIsNoPivotFieldFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares a defaultSort() on "name", which is not one of its pivot fields. It sorts only by its stored pivot fields: position.');

        MorphToMany::make('companies', ['agencies'])
            ->identifiedBy(static fn(): array => [])
            ->fields(Integer::make('position'), Str::make('label')->computed())
            ->defaultSort(new SortDirective(SortByField::make('name'), false))
            ->build();
    }

    #[Test]
    public function theVocabularyIsTheStoredPivotFieldsAndThePivotPrefixedFilters(): void
    {
        $relation = self::relation();

        self::assertSame(['position', 'linkedAt', 'secret'], \array_map(static fn($sort): string => $sort->key(), LinkRowQuery::sorts($relation)));
        self::assertSame(['position', 'linkedAt', 'secret'], \array_map(static fn($sort): string => $sort->key(), RelationSorts::vocabulary($relation, self::server())));
        self::assertSame(['minPosition', 'positions'], \array_map(static fn($filter): string => $filter->key(), LinkRowQuery::filters($relation)));
    }

    #[Test]
    public function theRowsFilterSortAndPage(): void
    {
        $relation = self::relation();
        $query = LinkRowQuery::resolve($relation, ['-position'], ['minPosition' => '2'], self::request(), new OffsetWindow(0, 1), countable: true);

        $page = (new InMemoryLinkRows())->queryLinkRows(self::owner(), $relation, $query, self::request());

        self::assertSame(2, $page->total);
        self::assertSame([['agencies', '2']], self::pairs($page->items));
    }

    #[Test]
    public function aCountFreePageSaysWhetherMoreRowsFollow(): void
    {
        $relation = self::relation();
        $query = LinkRowQuery::resolve($relation, ['position'], window: new OffsetWindow(1, 1));

        $page = (new InMemoryLinkRows())->queryLinkRows(self::owner(), $relation, $query, self::request());

        self::assertNull($page->total);
        self::assertTrue($page->hasMore);
        self::assertSame([['agencies', 7]], self::pairs($page->items));
    }

    #[Test]
    public function aListFilterSelectsByThePivotColumn(): void
    {
        $relation = self::relation();
        $query = LinkRowQuery::resolve($relation, filter: ['positions' => '1,3']);

        self::assertSame([['advertisers', 3], ['agencies', '2']], self::pairs((new InMemoryLinkRows())->queryLinkRows(self::owner(), $relation, $query, self::request())->items));
    }

    #[Test]
    public function withoutARequestedSortTheRelationsDefaultApplies(): void
    {
        $relation = (new SortedOwnerResource())->relationNamed('companies');
        self::assertInstanceOf(MorphToMany::class, $relation);

        $query = LinkRowQuery::resolve($relation);

        self::assertSame($relation->defaultSort(), $query->sorts);
    }

    #[Test]
    public function anUnknownSortKeyIsRejected(): void
    {
        $this->expectException(SortParamUnrecognized::class);

        LinkRowQuery::resolve(self::relation(), ['name']);
    }

    #[Test]
    public function aFilterOffThePivotIsRejected(): void
    {
        $this->expectException(FilterParamUnrecognized::class);

        LinkRowQuery::resolve(self::relation(), filter: ['name' => 'Ada']);
    }

    #[Test]
    public function aRelationNotIdentifiedHasNoLinkRows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no link rows to query');

        LinkRowQuery::resolve(MorphToMany::make('companies', ['agencies'])->build());
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

    private static function relation(): MorphToMany
    {
        $relation = (new PivotOwnerResource())->relationNamed('companies');
        self::assertInstanceOf(MorphToMany::class, $relation);

        return $relation;
    }

    private static function owner(): \stdClass
    {
        return (object) ['id' => '1', 'rows' => [
            (object) ['type' => 'agencies', 'key' => 7, 'position' => 2, 'linkedAt' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00'), 'secret' => 'x'],
            (object) ['type' => 'advertisers', 'key' => 3, 'position' => 1, 'linkedAt' => null, 'secret' => 'y'],
            (object) ['type' => 'agencies', 'key' => '2', 'position' => 3, 'linkedAt' => null, 'secret' => 'z'],
        ]];
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('GET', '/owners/1'));
    }

    /**
     * @param class-string<AbstractResource> $owner
     */
    private static function server(string $owner = PivotOwnerResource::class): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->register($owner)
            ->register(PivotAgencyResource::class)
            ->register(PivotAdvertiserResource::class)
            ->withPsr17($psr17, $psr17);
    }

    /**
     * @param class-string<AbstractResource> $owner
     *
     * @return list<array{type: string, id: string, meta?: array<string, mixed>}>
     */
    private function linkage(\stdClass $model, string $owner = PivotOwnerResource::class, ?RelationshipLinkageInterface $seam = null): array
    {
        $server = self::server($owner)->withRelationshipLinkage($seam);
        $response = DataResponse::fromResource($model, $server->serializerFor('owners'))->toPsrResponse($server, new ServerRequest('GET', '/owners/1'));

        /** @var array{data: array{relationships: array{companies: array{data: list<array{type: string, id: string, meta?: array<string, mixed>}>}}}} $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['data']['relationships']['companies']['data'];
    }
}

final class PivotOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            self::companies(),
        ];
    }

    /**
     * @return list<array{0: string, 1: string|int, 2: \stdClass}>
     */
    public static function linkRows(mixed $owner): array
    {
        $links = [];
        foreach ($owner instanceof \stdClass && \is_array($owner->rows) ? $owner->rows : [] as $row) {
            if ($row instanceof \stdClass && \is_string($row->type) && (\is_string($row->key) || \is_int($row->key))) {
                $links[] = [$row->type, $row->key, $row];
            }
        }

        return $links;
    }

    public static function companies(): MorphToManyBuilder
    {
        return MorphToMany::make('companies', ['agencies', 'advertisers'])
            ->identifiedBy(self::linkRows(...))
            ->fields(
                Integer::make('position'),
                DateTime::make('linkedAt')->readOnly(),
                Str::make('secret')->hidden(),
                Str::make('label')->computedUsing(static fn(mixed $edge): string => $edge instanceof PivotEdge && $edge->related !== null ? 'loaded' : 'unloaded'),
            )
            ->withFilters(
                Where::make('minPosition', 'pivot.position', '>=')->integer(),
                WhereIn::make('positions', 'pivot.position'),
                Where::make('name'),
            )
            ->withData();
    }
}

final class SortedOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            PivotOwnerResource::companies()->defaultSort(new SortDirective(SortByField::make('position'), false)),
        ];
    }
}

final class MetaOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphToMany::make('companies', ['agencies', 'advertisers'])
                ->identifiedBy(PivotOwnerResource::linkRows(...))
                ->fields(Integer::make('position'))
                ->identifierMeta(static fn(mixed $owner, mixed $member): array => $member instanceof IdentifiedMember
                    ? ['via' => $member->type . ':' . $member->id . ':' . ($member->row !== null ? 'row' : 'none')]
                    : [])
                ->withData(),
        ];
    }
}

final class PivotAgencyResource extends AbstractResource
{
    public static string $type = 'agencies';

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class PivotAdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function fields(): array
    {
        return [Id::make()];
    }
}
