<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * One link row a {@see LinkRowWritePlan} writes: the member it links and the row fields
 * to set, keyed by field name. An existing row's member carries the row to update. A new
 * row's member carries none, and its values hold the owner, type and id fields beside
 * any position and pivot fields.
 */
final readonly class LinkRowWrite
{
    /**
     * @param array<string, mixed> $values row field => value
     */
    public function __construct(
        public IdentifiedMember $member,
        public array $values,
    ) {}
}
