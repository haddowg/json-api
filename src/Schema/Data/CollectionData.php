<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Data;

/**
 * Accumulator for a collection (array) primary data response.
 * Returns all primary resources in the order they were added.
 *
 * @internal
 */
final class CollectionData extends AbstractData
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function transformPrimaryData(): array
    {
        return \array_values($this->primaryKeys);
    }
}
