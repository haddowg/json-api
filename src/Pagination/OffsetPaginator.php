<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Offset + limit strategy (`page[offset]` / `page[limit]`).
 *
 * Fluent and immutable: {@see make()} then `with…()` to override the query-param
 * keys and the defaults used when a parameter is absent.
 */
final readonly class OffsetPaginator implements Paginator
{
    public function __construct(
        public string $offsetKey = 'offset',
        public string $limitKey = 'limit',
        public int $defaultOffset = 0,
        public int $defaultLimit = 15,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withOffsetKey(string $offsetKey): self
    {
        return new self($offsetKey, $this->limitKey, $this->defaultOffset, $this->defaultLimit);
    }

    public function withLimitKey(string $limitKey): self
    {
        return new self($this->offsetKey, $limitKey, $this->defaultOffset, $this->defaultLimit);
    }

    public function withDefaultOffset(int $defaultOffset): self
    {
        return new self($this->offsetKey, $this->limitKey, $defaultOffset, $this->defaultLimit);
    }

    public function withDefaultLimit(int $defaultLimit): self
    {
        return new self($this->offsetKey, $this->limitKey, $this->defaultOffset, $defaultLimit);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return OffsetBasedPage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): OffsetBasedPage
    {
        $pagination = $request->getPagination();

        return new OffsetBasedPage(
            $items,
            $totalItems,
            QueryParam::int($pagination, $this->offsetKey, $this->defaultOffset),
            QueryParam::int($pagination, $this->limitKey, $this->defaultLimit),
        );
    }
}
