<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows that lack a related record on the named relationship (the
 * negation of {@see WhereHas}).
 */
final readonly class WhereDoesntHave implements Filter
{
    public function __construct(
        public string $key,
        public string $relationship,
    ) {}

    public static function make(string $key, ?string $relationship = null): self
    {
        return new self($key, $relationship ?? $key);
    }

    public function key(): string
    {
        return $this->key;
    }
}
