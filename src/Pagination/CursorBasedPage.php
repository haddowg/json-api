<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Profile\ProfileInterface;

/**
 * A page of cursor-based pagination (`page[after]` / `page[before]` /
 * `page[size]`), aligned to the published cursor-pagination profile.
 *
 * Emits `first`/`prev`/`next` only — **`last` is intentionally omitted**: a
 * cursor strategy has no total count by design (computing one to locate the last
 * page would defeat the purpose of cursors). `next` is emitted when more items
 * follow (`page[after]` = the last item's cursor); `prev` when items precede
 * (`page[before]` = the first item's cursor); `first` resets to the head of the
 * list. This page activates the cursor-pagination {@see profile()}.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class CursorBasedPage extends AbstractPage
{
    /**
     * @param iterable<T>          $items
     * @param int|string           $cursorBefore the cursor of the first item (for `prev`)
     * @param int|string           $cursorAfter  the cursor of the last item (for `next`)
     * @param int|string|null      $from         the id of the first row on the page (for `meta.page.from`), or null when empty
     * @param int|string|null      $to           the id of the last row on the page (for `meta.page.to`), or null when empty
     */
    public function __construct(
        iterable $items,
        public int $size,
        public int|string $cursorBefore,
        public int|string $cursorAfter,
        public bool $hasNext,
        public bool $hasPrevious,
        private readonly ?ProfileInterface $profile = null,
        public int|string|null $from = null,
        public int|string|null $to = null,
    ) {
        parent::__construct($items);
    }

    public function linkSet(string $uri, string $queryString): array
    {
        return [
            'self' => null,
            'first' => $this->paginatedLink($uri, $queryString, ['page' => ['size' => $this->size]]),
            'prev' => $this->hasPrevious
                ? $this->paginatedLink($uri, $queryString, ['page' => ['before' => $this->cursorBefore, 'size' => $this->size]])
                : null,
            'next' => $this->hasNext
                ? $this->paginatedLink($uri, $queryString, ['page' => ['after' => $this->cursorAfter, 'size' => $this->size]])
                : null,
            // `last` intentionally omitted for cursor pagination (no total count by design).
            'last' => null,
        ];
    }

    public function pageMeta(): array
    {
        $meta = ['perPage' => $this->size];

        // from/to are the ids of the first/last row on the page; omitted on an
        // empty page (both null) so meta.page never carries a null boundary.
        if ($this->from !== null) {
            $meta['from'] = $this->from;
        }
        if ($this->to !== null) {
            $meta['to'] = $this->to;
        }

        $meta['hasMore'] = $this->hasNext;

        return $meta;
    }

    public function profile(): ?ProfileInterface
    {
        return $this->profile;
    }

    /**
     * Returns the same page associated with the given profile (the cursor
     * paginator wires this so the page can advertise the profile downstream).
     *
     * @return self<T>
     */
    public function withProfile(ProfileInterface $profile): self
    {
        return new self(
            $this->items,
            $this->size,
            $this->cursorBefore,
            $this->cursorAfter,
            $this->hasNext,
            $this->hasPrevious,
            $profile,
            $this->from,
            $this->to,
        );
    }
}
