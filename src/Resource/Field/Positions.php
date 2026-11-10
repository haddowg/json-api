<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Computes the positions a persister writes for a relation positioned with
 * `positionedBy()` ({@see PositionedRelationInterface}). Members are keyed by any string
 * the persister chooses, such as the related id, or the type and id of a polymorphic
 * member; positions count from `0`.
 *
 * A position column is normally unique per owner, so rewriting positions in place can
 * collide with a row that hasn't moved yet. {@see plan()} splits a write into two
 * passes that never collide: write {@see PositionPlan::$temporary} first, moving every
 * row whose position changes past every position in use, then
 * {@see PositionPlan::$final}.
 */
final class Positions
{
    /**
     * The positions a replace writes: each member's index in the linkage. A member
     * repeated in the linkage keeps its first index, and later members close up.
     *
     * @param iterable<string> $linkage the members in linkage order
     *
     * @return array<string, int>
     */
    public static function forReplace(iterable $linkage): array
    {
        $positions = [];
        foreach ($linkage as $key) {
            if (!isset($positions[$key])) {
                $positions[$key] = \count($positions);
            }
        }

        return $positions;
    }

    /**
     * The positions an add writes for the members it adds, in linkage order after the
     * highest current position, or from `0` when there is none. A member already
     * present, or repeated in the request, is left out: it keeps its position.
     *
     * @param array<string, int> $current  the current members' positions
     * @param iterable<string>   $incoming the members the request adds, in linkage order
     *
     * @return array<string, int>
     */
    public static function forAdd(array $current, iterable $incoming): array
    {
        $next = $current === [] ? 0 : \max($current) + 1;
        $added = [];
        foreach ($incoming as $key) {
            if (!isset($current[$key]) && !isset($added[$key])) {
                $added[$key] = $next++;
            }
        }

        return $added;
    }

    /**
     * The two passes that take the members from `$current` to `$target`, and the
     * members to delete before them: those in `$current` but not in `$target`. A remove
     * passes the current positions less the removed members as `$target`, so nothing
     * moves and gaps stay.
     *
     * Write in this order: delete {@see PositionPlan::$removed}, then write
     * {@see PositionPlan::$temporary}, then {@see PositionPlan::$final}, inserting the
     * members `$current` doesn't hold at their final position.
     *
     * @param array<string, int> $current the current members' positions
     * @param array<string, int> $target  every remaining member's position afterwards
     */
    public static function plan(array $current, array $target): PositionPlan
    {
        $offset = \max([-1, ...\array_values($current), ...\array_values($target)]) + 1;

        $removed = [];
        foreach ($current as $key => $position) {
            if (!\array_key_exists($key, $target)) {
                $removed[] = (string) $key;
            }
        }

        $temporary = [];
        $final = [];
        foreach ($target as $key => $position) {
            $key = (string) $key;
            if (!\array_key_exists($key, $current)) {
                $final[$key] = $position;
            } elseif ($current[$key] !== $position) {
                $temporary[$key] = $offset + $position;
                $final[$key] = $position;
            }
        }

        return new PositionPlan($removed, $temporary, $final);
    }
}
