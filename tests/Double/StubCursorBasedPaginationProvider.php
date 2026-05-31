<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Pagination\CursorBasedPaginationLinkProviderTrait;
use haddowg\JsonApi\Schema\Pagination\PaginationLinkProviderInterface;

final class StubCursorBasedPaginationProvider implements PaginationLinkProviderInterface
{
    use CursorBasedPaginationLinkProviderTrait;

    public function __construct(
        private readonly mixed $firstItem,
        private readonly mixed $lastItem,
        private readonly mixed $currentItem,
        private readonly mixed $previousItem,
        private readonly mixed $nextItem,
        private readonly int $size,
    ) {}

    public function getFirstItem(): mixed
    {
        return $this->firstItem;
    }

    public function getLastItem(): mixed
    {
        return $this->lastItem;
    }

    public function getCurrentItem(): mixed
    {
        return $this->currentItem;
    }

    public function getPreviousItem(): mixed
    {
        return $this->previousItem;
    }

    public function getNextItem(): mixed
    {
        return $this->nextItem;
    }

    public function getSize(): int
    {
        return $this->size;
    }
}
