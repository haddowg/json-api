<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The stored identifiers a {@see WhereMorph} value selects: one JSON:API type and,
 * when the request names ids, their storage keys. `$ids` is `null` when the request
 * names none, so every target of `$type` matches, and an empty list matches nothing.
 */
final readonly class MorphSelection
{
    /**
     * @param list<mixed>|null $ids storage keys, decoded through the type's id encoder
     */
    public function __construct(
        public string $type,
        public ?array $ids = null,
    ) {}
}
