<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * One link row a {@see LinkRowWritePlan} writes: the member it links and the row fields
 * to set, keyed by field name. An existing row's member carries the row to update. A new
 * row's member carries none, and its values hold the owner, type and id fields beside
 * any position and pivot fields. An open pivot's extra members ride beside the values for
 * the entry's fill hook.
 */
final readonly class LinkRowWrite
{
    /**
     * @param array<string, mixed> $values     row field => value
     * @param array<string, mixed> $additional the written members of an open pivot the relation's
     *                                         `AdditionalProperties` entry takes, numbers normalized
     * @param array<string, mixed> $pivot      the member's written `meta.pivot`
     */
    public function __construct(
        public IdentifiedMember $member,
        public array $values,
        public array $additional = [],
        public array $pivot = [],
    ) {}

    /**
     * Hands {@see $additional} to the fill hook of the relation's `AdditionalProperties`
     * entry with `$row`, the row the write applies to, and returns the row the hook returns.
     * A write carrying none, or an entry with no fill hook, leaves the row as it is.
     */
    public function fillAdditional(PivotRelationInterface $relation, mixed $row): mixed
    {
        $entry = $relation->pivotAdditionalProperties();
        if ($this->additional === [] || $entry === null || !$entry->fills()) {
            return $row;
        }

        return $entry->fill($row, $this->additional, $this->pivot);
    }
}
