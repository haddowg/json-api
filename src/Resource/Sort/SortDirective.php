<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * One element of a requested sort order: the declared {@see SortInterface} the
 * request matched, plus its direction (a leading `-` in the `sort` parameter
 * means descending).
 *
 * A {@see SortHandlerInterface} receives the full ordered list of these —
 * most significant first — in a single call, because sort directives do not
 * compose commutatively (see the handler contract).
 */
final readonly class SortDirective
{
    public function __construct(
        public SortInterface $sort,
        public bool $descending,
    ) {}
}
