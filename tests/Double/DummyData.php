<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Data\AbstractData;

/**
 * Minimal {@see AbstractData} test double with empty primary data.
 */
final class DummyData extends AbstractData
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function transformPrimaryData(): array
    {
        return [];
    }
}
