<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BelongsToMany::class)]
#[CoversClass(PivotEdge::class)]
final class ComputedPivotFieldTest extends TestCase
{
    #[Test]
    public function onlyAFieldWithoutAColumnIsComputed(): void
    {
        self::assertTrue(BelongsToMany::isComputedPivotField(Str::make('a')->computed()->build()));
        self::assertTrue(BelongsToMany::isComputedPivotField(Str::make('b')->computedUsing(static fn(): string => 'b')->build()));
        self::assertFalse(BelongsToMany::isComputedPivotField(Str::make('c')->extractUsing(static fn(): string => 'c')->build()));
        self::assertFalse(BelongsToMany::isComputedPivotField(Str::make('d')->build()));
        self::assertFalse(BelongsToMany::isComputedPivotField(Str::make('e')->storedAs('e_column')->readOnly()->build()));

        // A stored field with an extract hook is read through it all the same.
        self::assertTrue(BelongsToMany::readsPivotFieldThroughHook(Str::make('c')->extractUsing(static fn(): string => 'c')->build()));
        self::assertTrue(BelongsToMany::readsPivotFieldThroughHook(Str::make('b')->computedUsing(static fn(): string => 'b')->build()));
        self::assertFalse(BelongsToMany::readsPivotFieldThroughHook(Str::make('d')->build()));
    }

    #[Test]
    public function itSplitsItsPivotFieldsIntoStoredAndComputed(): void
    {
        $relation = BelongsToMany::make('tags', 'tags')->fields(
            Integer::make('position'),
            Str::make('label')->computedUsing(static fn(): string => 'x'),
            Str::make('note')->extractUsing(static fn(): string => 'y'),
        )->build();

        self::assertSame(['position', 'note'], self::names($relation->storedPivotFields()));
        self::assertSame(['label'], self::names($relation->computedPivotFields()));

        $allComputed = BelongsToMany::make('tags', 'tags')->fields(Str::make('label')->computedUsing(static fn(): string => 'x'))->build();
        self::assertSame([], $allComputed->storedPivotFields());
    }

    #[Test]
    public function aComputedFieldIsNeverWritableEvenWhenNotDeclaredReadOnly(): void
    {
        $relation = BelongsToMany::make('tags', 'tags')->fields(
            Integer::make('position'),
            Str::make('note')->extractUsing(static fn(): string => 'y'),
            Str::make('label')->computed(),
        )->build();

        // A stored field with an extract hook stays writable to its column.
        self::assertSame(['position', 'note'], self::names($relation->writablePivotFields(true)));
        self::assertSame(['position', 'note'], self::names($relation->writablePivotFields(false)));
        // They still render.
        self::assertSame(['position', 'note', 'label'], self::names($relation->readablePivotFields()));
    }

    #[Test]
    public function computedValuesReadAnEdgeOfTheParentAndTheRelatedMember(): void
    {
        $seen = null;
        $relation = BelongsToMany::make('members', 'users')->fields(
            Integer::make('position'),
            Str::make('role')->computedUsing(static function (mixed $edge, JsonApiRequestInterface $request, string $name) use (&$seen): string {
                $seen = [$edge, $name];

                if (!$edge instanceof PivotEdge || !\is_array($edge->parent) || !\is_array($edge->related)) {
                    return '';
                }
                $parentId = $edge->parent['id'] ?? null;
                $relatedId = $edge->related['id'] ?? null;

                return \is_string($parentId) && \is_string($relatedId) ? $parentId . ':' . $relatedId : '';
            }),
            DateTime::make('since')->computedUsing(static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-01-01T12:00:00+00:00')),
        )->build();

        $values = $relation->hookedPivotValues(['id' => 'team-1'], ['id' => 'user-7'], ['position' => 3], new StubJsonApiRequest(), 'Europe/Paris');

        // Only the hooked fields: the host renders the stored position itself.
        self::assertSame(['role' => 'team-1:user-7', 'since' => '2026-01-01T13:00:00+01:00'], $values);
        self::assertIsArray($seen);
        self::assertEquals(new PivotEdge(['id' => 'team-1'], ['id' => 'user-7'], ['position' => 3]), $seen[0]);
        self::assertSame('role', $seen[1]);
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

    #[Test]
    public function aStoredFieldWithAnExtractHookReadsTheStoredValuesOffTheEdge(): void
    {
        $relation = BelongsToMany::make('tags', 'tags')->fields(
            Integer::make('position'),
            Str::make('note')->extractUsing(static function (mixed $edge): ?string {
                $note = $edge instanceof PivotEdge ? ($edge->pivot['note'] ?? null) : null;

                return \is_string($note) ? \strtoupper($note) : null;
            }),
        )->build();

        self::assertSame(
            ['note' => 'HELLO'],
            $relation->hookedPivotValues(['id' => '1'], ['id' => '2'], ['position' => 1, 'note' => 'hello'], new StubJsonApiRequest()),
        );
        self::assertSame(['note' => null], $relation->hookedPivotValues(['id' => '1'], ['id' => '2'], [], new StubJsonApiRequest()));
    }
}
