<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Data;

/**
 * Accumulator for a single-resource primary data response.
 * Returns only the first primary resource (or null when none is present).
 *
 * @internal
 */
class SingleResourceData extends AbstractData
{
    /**
     * @return array<string, mixed>|null
     */
    public function transformPrimaryData(): ?array
    {
        if ($this->hasPrimaryResources() === false) {
            return null;
        }

        \reset($this->primaryKeys);
        $key = \key($this->primaryKeys);

        return $this->resources[$key];
    }
}
