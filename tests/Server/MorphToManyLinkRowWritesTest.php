<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowWrite;
use haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\LinkRowFields;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\MorphToManyBuilder;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The link-row writes core plans for a `MorphToMany` declared with `identifiedByRows()`,
 * applied to rows in memory by the witness, which rejects a pass a unique owner and
 * position key would.
 */
#[CoversClass(LinkRowWritePlan::class)]
#[CoversClass(LinkRowWrite::class)]
#[CoversClass(InMemoryLinkRows::class)]
#[CoversClass(LinkRowFields::class)]
#[CoversClass(MorphToMany::class)]
#[CoversClass(MorphToManyBuilder::class)]
final class MorphToManyLinkRowWritesTest extends TestCase
{
    #[Test]
    public function aReplaceDeletesMovesInsertsAndWritesPivotsWithoutCollision(): void
    {
        $relation = self::positioned();
        $owner = self::owner();

        $plan = self::plan($relation, $owner, Mode::Replace, [
            ['agencies', 2, ['note' => 'kept']],
            ['advertisers', 5, ['note' => 'new']],
            ['agencies', 7],
        ]);

        self::assertSame([['advertisers', 3]], self::pairs($plan->deletes));
        self::assertSame([['agencies', 2, ['position' => 3]], ['agencies', 7, ['position' => 5]]], self::writes($plan->temporary));
        self::assertSame([['agencies', 7, ['position' => 2]], ['agencies', 2, ['note' => 'kept', 'position' => 0]]], self::writes($plan->updates));
        self::assertSame([['advertisers', 5, ['owner' => $owner, 'kind' => 'advertiser', 'key' => 5, 'note' => 'new', 'position' => 1]]], self::writes($plan->inserts));

        (new InMemoryLinkRows())->write($relation, $owner, $plan);

        self::assertSame([['agency', 7, 2, null], ['agency', 2, 0, 'kept'], ['advertiser', 5, 1, 'new']], self::rows($owner));
        self::assertSame([['agencies', 2], ['advertisers', 5], ['agencies', 7]], self::pairs(self::linkage($relation, $owner)));
    }

    #[Test]
    public function aReplaceKeepsHiddenMembersAfterTheLinkage(): void
    {
        $relation = self::positioned();
        $owner = self::owner();

        $plan = self::plan($relation, $owner, Mode::Replace, [['agencies', 2]], hidden: [['agencies', 7]]);
        (new InMemoryLinkRows())->write($relation, $owner, $plan);

        self::assertSame([['advertisers', 3]], self::pairs($plan->deletes));
        self::assertSame([['agency', 7, 1, null], ['agency', 2, 0, null]], self::rows($owner));
    }

    #[Test]
    public function anAddAppendsAfterTheHighestPositionAndUpdatesANamedMembersPivot(): void
    {
        $relation = self::positioned();
        $owner = self::owner();

        $plan = self::plan($relation, $owner, Mode::Add, [['publishers', 9], ['agencies', 7, ['note' => 'again']]]);
        (new InMemoryLinkRows())->write($relation, $owner, $plan);

        self::assertSame([], $plan->deletes);
        self::assertSame([], $plan->temporary);
        self::assertSame([['agency', 7, 0, 'again'], ['advertiser', 3, 1, null], ['agency', 2, 2, null], ['publisher', 9, 3, null]], self::rows($owner));
    }

    #[Test]
    public function aRemoveDeletesTheNamedMembersLeavingGapsAndHiddenMembers(): void
    {
        $relation = self::positioned();
        $owner = self::owner();

        $plan = self::plan($relation, $owner, Mode::Remove, [['advertisers', 3], ['agencies', 7]], hidden: [['agencies', 7]]);
        (new InMemoryLinkRows())->write($relation, $owner, $plan);

        self::assertSame([['advertisers', 3]], self::pairs($plan->deletes));
        self::assertSame([], $plan->updates);
        self::assertSame([['agency', 7, 0, null], ['agency', 2, 2, null]], self::rows($owner));
    }

    #[Test]
    public function aRowWithNoPositionJoinsTheOrderAfterTheRest(): void
    {
        $relation = self::positioned();
        $owner = self::owner();
        self::assertIsArray($owner->links);
        $owner->links[] = (object) ['kind' => 'publisher', 'key' => 4, 'position' => null, 'note' => null];

        $plan = self::plan($relation, $owner, Mode::Add, [['agencies', 1]]);
        (new InMemoryLinkRows())->write($relation, $owner, $plan);

        self::assertSame([['agency', 7, 0, null], ['advertiser', 3, 1, null], ['agency', 2, 2, null], ['publisher', 4, 4, null], ['agency', 1, 3, null]], self::rows($owner));
    }

    #[Test]
    public function anUnpositionedRelationWritesNoPositions(): void
    {
        $relation = self::builder()->build();
        $owner = self::owner();

        $plan = self::plan($relation, $owner, Mode::Replace, [['advertisers', 3, ['note' => 'n']], ['agencies', 1]]);

        self::assertSame([['agencies', 7], ['agencies', 2]], self::pairs($plan->deletes));
        self::assertSame([], $plan->temporary);
        self::assertSame([['advertisers', 3, ['note' => 'n']]], self::writes($plan->updates));
        self::assertSame([['agencies', 1, ['owner' => $owner, 'kind' => 'agency', 'key' => 1]]], self::writes($plan->inserts));
    }

    #[Test]
    public function aLinkageTypeTheRelationDoesNotDeclareIsALogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot link a member of type "robots"');

        self::plan(self::positioned(), self::owner(), Mode::Add, [['robots', 1]]);
    }

    #[Test]
    public function aClosureRelationHasNoPlan(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('its fillUsing() writes them');

        LinkRowWritePlan::for(MorphToMany::make('companies', ['agencies'])->identifiedBy(static fn(): array => [])->build(), new \stdClass(), Mode::Add, [], []);
    }

    #[Test]
    public function theWitnessRejectsAPassThatCollides(): void
    {
        $relation = self::positioned();
        $owner = self::owner();
        $member = $relation->identifiedMembers($owner, self::request())[0];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('put two rows at position 1');

        (new InMemoryLinkRows())->write($relation, $owner, new LinkRowWritePlan(updates: [new LinkRowWrite($member, ['position' => 1])]));
    }

    #[Test]
    public function theDefaultWriteLeavesTheRowsAlone(): void
    {
        $relation = self::positioned();
        $owner = self::owner();
        $before = self::rows($owner);

        $relation->applyToMany($owner, new ToManyRelationship([]), Mode::Replace);
        $relation->hydrateRelationship($owner, new ToManyRelationship([]));

        self::assertSame($before, self::rows($owner));
    }

    #[Test]
    public function aNewRowWritesTheOneStoredValueOfItsType(): void
    {
        $fields = self::positioned()->linkRowFields();
        self::assertNotNull($fields);

        self::assertSame('publisher', $fields->storedValueFor('publishers'));

        $this->expectException(\LogicException::class);
        $fields->storedValueFor('robots');
    }

    #[Test]
    public function aTypeMapWithTwoValuesForOneTypeFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('maps the stored values "agency", "agent" to the type "agencies"');

        self::builder()->identifiedByRows(owner: 'owner', type: 'kind', id: 'key', typeMap: ['agency' => 'agencies', 'agent' => 'agencies', 'advertiser' => 'advertisers', 'publisher' => 'publishers'])->build();
    }

    #[Test]
    public function aTypeMapMissingADeclaredTypeFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('maps no stored value to the type "publishers"');

        self::builder()->identifiedByRows(owner: 'owner', type: 'kind', id: 'key', typeMap: ['agency' => 'agencies', 'advertiser' => 'advertisers'])->build();
    }

    #[Test]
    public function fillUsingOnADeclaredRelationFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares both identifiedByRows() and fillUsing()');

        self::builder()->fillUsing(static fn(mixed $owner): mixed => $owner)->build();
    }

    private static function builder(): MorphToManyBuilder
    {
        return MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
            ->storedAs('links')
            ->through(\stdClass::class)
            ->identifiedByRows(owner: 'owner', type: 'kind', id: 'key', typeMap: ['agency' => 'agencies', 'advertiser' => 'advertisers', 'publisher' => 'publishers'])
            ->fields(Integer::make('position'), Str::make('note'));
    }

    private static function positioned(): MorphToMany
    {
        return self::builder()->positionedBy('position')->build();
    }

    private static function owner(): \stdClass
    {
        return (object) ['links' => [
            (object) ['kind' => 'agency', 'key' => 7, 'position' => 0, 'note' => null],
            (object) ['kind' => 'advertiser', 'key' => 3, 'position' => 1, 'note' => null],
            (object) ['kind' => 'agency', 'key' => 2, 'position' => 2, 'note' => null],
        ]];
    }

    /**
     * @param list<array{0: string, 1: string|int, 2?: array<string, mixed>}> $linkage
     * @param list<array{0: string, 1: string|int}>                           $hidden
     */
    private static function plan(MorphToMany $relation, \stdClass $owner, Mode $mode, array $linkage, array $hidden = []): LinkRowWritePlan
    {
        return LinkRowWritePlan::for($relation, $owner, $mode, $linkage, $relation->identifiedMembers($owner, self::request()), $hidden);
    }

    /**
     * @return list<IdentifiedMember>
     */
    private static function linkage(MorphToMany $relation, \stdClass $owner): array
    {
        $items = (new InMemoryLinkRows())->queryLinkRows($owner, $relation, \haddowg\JsonApi\Collection\LinkRows\LinkRowQuery::resolve($relation), self::request())->items;

        return \array_values(\is_array($items) ? $items : \iterator_to_array($items, false));
    }

    /**
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed}>
     */
    private static function rows(\stdClass $owner): array
    {
        $rows = [];
        foreach (\is_array($owner->links) ? $owner->links : [] as $row) {
            self::assertInstanceOf(\stdClass::class, $row);
            $rows[] = [$row->kind, $row->key, $row->position ?? null, $row->note ?? null];
        }

        return $rows;
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

    /**
     * @param list<LinkRowWrite> $writes
     *
     * @return list<array{0: string, 1: string|int, 2: array<string, mixed>}>
     */
    private static function writes(array $writes): array
    {
        return \array_map(static fn(LinkRowWrite $write): array => [$write->member->type, $write->member->id, $write->values], $writes);
    }

    private static function request(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/owners/1'));
    }
}
