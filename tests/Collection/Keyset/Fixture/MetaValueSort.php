<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Collection\Keyset\Fixture;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;

/**
 * Sorts by one member of a row's `meta` map, the shape a sort family resolves to.
 */
final readonly class MetaValueSort implements KeysetSortInterface
{
    public function __construct(
        public string $key,
        public string $member,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function keysetValue(mixed $row): mixed
    {
        if (!\is_object($row) || !\property_exists($row, 'meta') || !\is_array($row->meta)) {
            return null;
        }

        return $row->meta[$this->member] ?? null;
    }
}
