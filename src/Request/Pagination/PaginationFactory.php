<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

final readonly class PaginationFactory
{
    public function __construct(
        private JsonApiRequestInterface $request,
    ) {}

    /**
     * Returns a FixedPageBasedPagination built from the "page[number]" query parameter.
     *
     * The stored value is the "page[number]" query parameter if present, or $defaultPage otherwise.
     */
    public function createFixedPageBasedPagination(int $defaultPage = 0): FixedPageBasedPagination
    {
        return FixedPageBasedPagination::fromPaginationQueryParams($this->request->getPagination(), $defaultPage);
    }

    /**
     * Returns a PageBasedPagination built from the "page[number]" and "page[size]" query parameters.
     *
     * The stored values are the "page[number]" and "page[size]" query parameters if present,
     * or $defaultPage and $defaultSize otherwise.
     */
    public function createPageBasedPagination(int $defaultPage = 0, int $defaultSize = 0): PageBasedPagination
    {
        return PageBasedPagination::fromPaginationQueryParams($this->request->getPagination(), $defaultPage, $defaultSize);
    }

    /**
     * Returns an OffsetBasedPagination built from the "page[offset]" and "page[limit]" query parameters.
     *
     * The stored values are the "page[offset]" and "page[limit]" query parameters if present,
     * or $defaultOffset and $defaultLimit otherwise.
     */
    public function createOffsetBasedPagination(int $defaultOffset = 0, int $defaultLimit = 0): OffsetBasedPagination
    {
        return OffsetBasedPagination::fromPaginationQueryParams($this->request->getPagination(), $defaultOffset, $defaultLimit);
    }

    /**
     * Returns a FixedCursorBasedPagination built from the "page[cursor]" query parameter.
     *
     * The stored value is the "page[cursor]" query parameter if present, or $defaultCursor otherwise.
     */
    public function createFixedCursorBasedPagination(mixed $defaultCursor = null): FixedCursorBasedPagination
    {
        return FixedCursorBasedPagination::fromPaginationQueryParams($this->request->getPagination(), $defaultCursor);
    }

    /**
     * Returns a CursorBasedPagination built from the "page[cursor]" and "page[size]" query parameters.
     *
     * The stored values are the "page[cursor]" and "page[size]" query parameters if present,
     * or $defaultCursor and $defaultSize otherwise.
     */
    public function createCursorBasedPagination(mixed $defaultCursor = null, int $defaultSize = 0): CursorBasedPagination
    {
        return CursorBasedPagination::fromPaginationQueryParams($this->request->getPagination(), $defaultCursor, $defaultSize);
    }
}
