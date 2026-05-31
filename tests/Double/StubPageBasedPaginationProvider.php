<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Pagination\PageBasedPaginationLinkProviderTrait;
use haddowg\JsonApi\Schema\Pagination\PaginationLinkProviderInterface;

final class StubPageBasedPaginationProvider implements PaginationLinkProviderInterface
{
    use PageBasedPaginationLinkProviderTrait;

    public function __construct(
        private readonly int $totalItems,
        private readonly int $page,
        private readonly int $size,
    ) {}

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getSize(): int
    {
        return $this->size;
    }
}
