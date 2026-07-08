<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\Boolean;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In-memory reference conformance for the server-composed filter groups
 * ({@see WhereAll} / {@see WhereAny}) and the {@see Where::fixed()} wither — the
 * contract every provider adapter (Doctrine, Eloquent) must witness in kind.
 */
#[CoversClass(WhereGroup::class)]
#[CoversClass(WhereAll::class)]
#[CoversClass(WhereAny::class)]
#[CoversClass(Where::class)]
#[CoversClass(ArrayFilterHandler::class)]
#[Group('spec:filtering')]
final class FilterGroupTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function data(): array
    {
        return [
            ['id' => '1', 'name' => 'Foo Bar', 'email' => 'x@y.com', 'priority' => 10, 'flagged' => true, 'status' => 'published'],
            ['id' => '2', 'name' => 'Alice', 'email' => 'foo@z.com', 'priority' => 3, 'flagged' => false, 'status' => 'draft'],
            ['id' => '3', 'name' => 'Bob', 'email' => 'bob@z.com', 'priority' => 8, 'flagged' => true, 'status' => 'published'],
            ['id' => '4', 'name' => 'Carol', 'email' => 'carol@z.com', 'priority' => 2, 'flagged' => true, 'status' => 'archived'],
        ];
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $result): array
    {
        self::assertIsArray($result);
        $ids = [];
        foreach ($result as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    #[Test]
    public function whereAnyFansOneValueAcrossColumnsAsAMultiColumnSearch(): void
    {
        // filter[q]=foo -> name LIKE foo OR email LIKE foo: the group's single value
        // is passed to every child (fan-out search).
        $filter = WhereAny::make('q', Contains::make('name'), Contains::make('email'));

        $result = (new ArrayFilterHandler())->apply($filter, $this->data(), 'foo');

        // row 1 matches on name ("Foo Bar"), row 2 matches on email ("foo@z.com").
        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function whereAllCombinesChildrenWithAnd(): void
    {
        // A fanning AND group: filter[a]=... applied to every child.
        $filter = WhereAll::make('same', Contains::make('name'), Contains::make('email'));

        // Only row where BOTH name and email contain "b": row 3 ("Bob" / "bob@z.com").
        $result = (new ArrayFilterHandler())->apply($filter, $this->data(), 'b');

        self::assertSame(['3'], $this->ids($result));
    }

    #[Test]
    public function whereAllOfFixedChildrenIsACannedToggleThatIgnoresTheRequestValue(): void
    {
        // filter[urgent] present -> priority > 5 AND flagged = true, via fixed
        // children; the request value is ignored entirely.
        $filter = WhereAll::make(
            'urgent',
            GreaterThan::make('priority')->fixed(5),
            Boolean::make('flagged')->fixed(true),
        );

        $handler = new ArrayFilterHandler();

        // priority>5 AND flagged: rows 1 (10/true) and 3 (8/true).
        self::assertSame(['1', '3'], $this->ids($handler->apply($filter, $this->data(), 'anything')));
        // Any other value yields the identical result — the value is ignored.
        self::assertSame(['1', '3'], $this->ids($handler->apply($filter, $this->data(), '0')));
    }

    #[Test]
    public function nestedGroupsEvaluateAAndBOrC(): void
    {
        // (name LIKE <value>) AND ((flagged = true) OR (priority > 100)).
        // The value fans to the Contains child; the fixed children ignore it, and the
        // nested WhereAny re-enters the same dispatch automatically.
        $filter = WhereAll::make(
            'search',
            Contains::make('name'),
            WhereAny::make(
                'inner',
                Boolean::make('flagged')->fixed(true),
                GreaterThan::make('priority')->fixed(100),
            ),
        );

        $result = (new ArrayFilterHandler())->apply($filter, $this->data(), 'bob');

        // "Bob" matches the name search and is flagged -> row 3 only.
        self::assertSame(['3'], $this->ids($result));
    }

    #[Test]
    public function fixedStandalonePinsTheComparedValueRegardlessOfTheSentValue(): void
    {
        // filter[status]=<anything> -> status = 'published', the sent value ignored.
        $filter = Where::make('status')->fixed('published');

        $handler = new ArrayFilterHandler();

        // Sending 'draft' does NOT filter for drafts — the fixed 'published' wins.
        self::assertSame(['1', '3'], $this->ids($handler->apply($filter, $this->data(), 'draft')));
        self::assertSame(['1', '3'], $this->ids($handler->apply($filter, $this->data(), 'archived')));
    }

    #[Test]
    public function fixedInheritsOntoConvenienceSubclassesAndKeepsTheirIdentity(): void
    {
        // ->fixed() is on the base Where, so a GreaterThan keeps its `>` identity.
        $filter = GreaterThan::make('priority')->fixed(5);

        self::assertInstanceOf(GreaterThan::class, $filter);

        $result = (new ArrayFilterHandler())->apply($filter, $this->data(), 'ignored');

        // priority > 5: rows 1 (10) and 3 (8).
        self::assertSame(['1', '3'], $this->ids($result));
    }

    #[Test]
    public function aGroupChildResolvedByARegisteredArmRunsThroughTheDefaultFallthrough(): void
    {
        // A custom filter child re-enters predicate() and resolves via the
        // default => armPredicate fallthrough, exactly like a top-level custom filter.
        $custom = new class implements FilterInterface {
            public function key(): string
            {
                return 'minPriority';
            }

            public function constraints(): array
            {
                return [];
            }
        };

        $arm = new class implements ArrayFilterArmInterface {
            public function supports(FilterInterface $filter): bool
            {
                return $filter->key() === 'minPriority';
            }

            public function predicate(FilterInterface $filter, mixed $value): \Closure
            {
                $min = \is_string($value) ? (int) $value : 0;

                return static function (mixed $row) use ($min): bool {
                    if (!\is_array($row)) {
                        return false;
                    }
                    $priority = $row['priority'] ?? null;

                    return \is_int($priority) && $priority >= $min;
                };
            }
        };

        $filter = WhereAny::make('x', $custom, Boolean::make('flagged')->fixed(false));

        $result = (new ArrayFilterHandler([$arm]))->apply($filter, $this->data(), '8');

        // priority >= 8 (rows 1, 3) OR flagged = false (row 2).
        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function fixedIsPresenceTriggeredAndIsNotFoldedInOnOmission(): void
    {
        // Distinct from ->default(): a fixed filter never applies when its key is
        // absent (it declares no default to fold in), and it reports presence-trigger.
        $filter = Where::make('status')->fixed('published');

        self::assertInstanceOf(PresenceTriggeredFilter::class, $filter);
        self::assertTrue($filter->isPresenceTriggered());
        self::assertFalse($filter->hasDefault());
        self::assertTrue($filter->hasFixed);
        self::assertSame('published', $filter->fixedValue);

        // Omitted key stays omitted (a default WOULD have filled it in).
        self::assertSame([], FilterDefaults::apply([], [$filter]));
    }

    #[Test]
    public function fixedDropsValueConstraintsSinceTheClientValueIsIgnored(): void
    {
        // GreaterThan presets a numeric() constraint; ->fixed() drops it because
        // there is no client value to validate — any sent value triggers the filter.
        $filter = GreaterThan::make('priority')->fixed(5);

        self::assertSame([], $filter->constraints());
    }

    #[Test]
    public function anAllFixedGroupIsPresenceTriggeredWhileAFanningGroupIsNot(): void
    {
        $allFixed = WhereAll::make(
            'urgent',
            GreaterThan::make('priority')->fixed(5),
            Boolean::make('flagged')->fixed(true),
        );
        $fanning = WhereAny::make('q', Contains::make('name'), Contains::make('email'));

        self::assertTrue($allFixed->isPresenceTriggered());
        self::assertFalse($fanning->isPresenceTriggered());

        // A fanning group can declare its shared value's constraints; an all-fixed
        // group declares none.
        self::assertSame([], $allFixed->constraints());
        self::assertCount(1, $fanning->numeric()->constraints());
    }

    #[Test]
    public function anEmptyGroupIsNotPresenceTriggered(): void
    {
        self::assertFalse(WhereAll::make('x')->isPresenceTriggered());
        self::assertFalse(WhereAny::make('x')->isPresenceTriggered());
    }
}
