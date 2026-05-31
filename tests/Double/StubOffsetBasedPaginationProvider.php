<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Pagination\OffsetBasedPaginationLinkProviderTrait;
use haddowg\JsonApi\Schema\Pagination\PaginationLinkProviderInterface;

final class StubOffsetBasedPaginationProvider implements PaginationLinkProviderInterface
{
    use OffsetBasedPaginationLinkProviderTrait;

    public function __construct(
        private readonly int $totalItems,
        private readonly int $offset,
        private readonly int $limit,
    ) {}

    protected function getTotalItems(): int
    {
        return $this->totalItems;
    }

    protected function getOffset(): int
    {
        return $this->offset;
    }

    protected function getLimit(): int
    {
        return $this->limit;
    }
}
