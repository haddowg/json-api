<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The writes that move a positioned relation's members to new positions without
 * breaking a unique owner and position key, from {@see Positions::plan()}. Apply them in
 * property order, each pass complete before the next.
 */
final readonly class PositionPlan
{
    /**
     * @param list<string>       $removed   the members to delete first
     * @param array<string, int> $temporary the members that move, each at a position past every one in use
     * @param array<string, int> $final     the members that move or are new, at their final positions
     */
    public function __construct(
        public array $removed,
        public array $temporary,
        public array $final,
    ) {}
}
