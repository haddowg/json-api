<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Internal\StoredPair;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Positions;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * The link-row writes one relationship write makes on a {@see MorphToMany} declared with
 * `identifiedByRows()`, which a data layer applies in place of a `fillUsing()` hook.
 *
 * Apply it in property order, each pass complete before the next, so a unique owner and
 * position key never sees two rows at one position: delete {@see $deletes}, move the rows
 * in {@see $temporary} past every position in use, write {@see $updates}, then insert
 * {@see $inserts}. Without a position only the deletes, the pivot updates and the inserts
 * carry anything. {@see InMemoryLinkRows::write()} applies a plan to rows held in memory.
 */
final readonly class LinkRowWritePlan
{
    /**
     * @param list<IdentifiedMember> $deletes   existing members whose rows go, each carrying its row
     * @param list<LinkRowWrite>     $temporary existing rows that move, each set to a position past every one in use
     * @param list<LinkRowWrite>     $updates   existing rows set to their final position, their written pivot fields, or both
     * @param list<LinkRowWrite>     $inserts   new rows, with the owner, type and id fields, any final position and their written pivot fields
     */
    public function __construct(
        public array $deletes = [],
        public array $temporary = [],
        public array $updates = [],
        public array $inserts = [],
    ) {}

    /**
     * The plan for writing `$linkage` to `$parent`'s link rows in `$mode`.
     *
     * `$linkage` lists the request's members in order as `[type, id, pivot]`, `id` the
     * storage key and `pivot` the identifier's `meta.pivot`, or `[]`. `$current` is every
     * member the parent links now, each carrying its row, including those a scope hides
     * from the client, which `$hidden` names as `[type, id]` pairs.
     *
     * A replace deletes every current member the linkage leaves out, except a hidden one,
     * and inserts the members it adds. An add inserts the members not yet linked. A remove
     * deletes the linkage's members, never a hidden one. A member already linked that the
     * linkage names again keeps its row and takes the pivot fields it sends.
     *
     * A positioned relation writes positions as {@see Positions} sets them: a replace puts
     * each member at its linkage index with the hidden members after them in their current
     * order, an add appends after the highest position, and a remove leaves gaps. A current
     * row with no position is treated as new to the order.
     *
     * Pivot values are read off `pivot` for the relation's writable pivot fields, the
     * create-context ones for a new row and the update-context ones for an existing row,
     * each cast by its field and keyed by its row field.
     *
     * @param iterable<array{0: string, 1: string|int, 2?: array<string, mixed>}> $linkage
     * @param iterable<IdentifiedMember>                                          $current
     * @param iterable<array{0: string, 1: string|int}>                           $hidden
     *
     * @throws \LogicException when the relation was not declared with identifiedByRows(),
     *                         or the linkage names a type it does not declare
     */
    public static function for(MorphToMany $relation, mixed $parent, Mode $mode, iterable $linkage, iterable $current, iterable $hidden = []): self
    {
        $fields = $relation->linkRowFields()
            ?? throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedByRows(), so core cannot plan its link-row writes; its fillUsing() writes them.', $relation->name()));

        /** @var array<string, IdentifiedMember> $existing */
        $existing = [];
        foreach ($current as $member) {
            $existing[self::key($member->type, $member->id)] ??= $member;
        }

        $hiddenKeys = [];
        foreach ($hidden as [$type, $id]) {
            $key = self::key($type, $id);
            if (isset($existing[$key])) {
                $hiddenKeys[$key] = true;
            }
        }

        /** @var array<string, array{0: string, 1: string|int, 2: array<string, mixed>}> $incoming */
        $incoming = [];
        foreach ($linkage as $link) {
            if (!StoredPair::isPair([$link[0], $link[1]]) || !\in_array($link[0], $fields->types, true)) {
                throw new \LogicException(\sprintf(
                    'MorphToMany relation "%s" cannot link a member of type "%s"; it declares %s.',
                    $relation->name(),
                    $link[0],
                    \implode(', ', $fields->types),
                ));
            }
            $incoming[self::key($link[0], $link[1])] ??= [$link[0], $link[1], $link[2] ?? []];
        }

        $deleted = match ($mode) {
            Mode::Replace => \array_diff_key($existing, $incoming, $hiddenKeys),
            Mode::Add => [],
            Mode::Remove => \array_diff_key(\array_intersect_key($existing, $incoming), $hiddenKeys),
        };
        $added = $mode === Mode::Remove ? [] : \array_diff_key($incoming, $existing);
        $kept = \array_diff_key($existing, $deleted);

        [$temporary, $final] = self::positions($relation, $mode, $existing, $kept, \array_keys($incoming), $hiddenKeys);
        $position = self::positionColumn($relation);

        $temporaryWrites = [];
        foreach ($temporary as $key => $at) {
            \assert($position !== null);
            $temporaryWrites[] = new LinkRowWrite($existing[$key], [$position => $at]);
        }

        $updates = [];
        foreach ($kept as $key => $member) {
            $values = $mode === Mode::Remove || !isset($incoming[$key]) ? [] : self::pivotValues($relation, $incoming[$key][2], false);
            if ($position !== null && isset($final[$key])) {
                $values[$position] = $final[$key];
            }
            if ($values !== []) {
                $updates[] = new LinkRowWrite($member, $values);
            }
        }

        $inserts = [];
        foreach ($added as $key => [$type, $id, $pivot]) {
            $values = [
                $fields->owner => $parent,
                $fields->type => $fields->storedValueFor($type),
                $fields->id => $id,
                ...self::pivotValues($relation, $pivot, true),
            ];
            if ($position !== null && isset($final[$key])) {
                $values[$position] = $final[$key];
            }
            $inserts[] = new LinkRowWrite(new IdentifiedMember($type, $id), $values);
        }

        return new self(\array_values($deleted), $temporaryWrites, $updates, $inserts);
    }

    /**
     * The row field a positioned relation stores positions in, or `null`.
     */
    public static function positionColumn(MorphToMany $relation): ?string
    {
        $name = $relation->positionField();
        if ($name === null) {
            return null;
        }

        $field = $relation->pivotField($name);

        return $field === null ? $name : ($field->column() ?? $name);
    }

    /**
     * The temporary and final positions, keyed like the members.
     *
     * @param array<string, IdentifiedMember> $existing
     * @param array<string, IdentifiedMember> $kept
     * @param list<string>                    $sent
     * @param array<string, true>             $hidden
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private static function positions(MorphToMany $relation, Mode $mode, array $existing, array $kept, array $sent, array $hidden): array
    {
        $column = self::positionColumn($relation);
        if ($column === null) {
            return [[], []];
        }

        $current = [];
        foreach ($existing as $key => $member) {
            $at = $member->row === null ? null : Accessor::get($member->row, $column);
            if (\is_int($at) || (\is_string($at) && \ctype_digit($at))) {
                $current[$key] = (int) $at;
            }
        }

        $target = match ($mode) {
            Mode::Replace => Positions::forReplace([...$sent, ...\array_keys(\array_diff_key(\array_intersect_key($existing, $hidden), \array_flip($sent)))]),
            Mode::Add => \array_intersect_key($current, $kept) + Positions::forAdd($current, $sent),
            Mode::Remove => \array_intersect_key($current, $kept),
        };

        // A kept row without a position joins the order after the rest; a remove moves nothing.
        foreach ($mode === Mode::Remove ? [] : $kept as $key => $member) {
            if (!isset($target[$key])) {
                $target[$key] = $target === [] ? 0 : \max($target) + 1;
            }
        }

        $plan = Positions::plan(\array_intersect_key($current, $kept), $target);

        return [$plan->temporary, $plan->final];
    }

    /**
     * @param array<string, mixed> $pivot
     *
     * @return array<string, mixed>
     */
    private static function pivotValues(MorphToMany $relation, array $pivot, bool $creating): array
    {
        $values = [];
        foreach ($relation->writablePivotFields($creating) as $field) {
            \assert($field instanceof FieldInterface);
            if (\array_key_exists($field->name(), $pivot)) {
                $values[$field->column() ?? $field->name()] = $field->castWireValue($pivot[$field->name()]);
            }
        }

        return $values;
    }

    private static function key(string $type, string|int $id): string
    {
        return $type . "\0" . $id;
    }
}
