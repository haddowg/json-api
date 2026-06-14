<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows that have a related record on the named relationship (optionally
 * narrowed by a nested filter). Data-layer-specific; core ships the metadata,
 * adapters interpret the relationship traversal.
 */
final readonly class WhereHas implements \haddowg\JsonApi\Resource\Filter\FilterInterface
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

    /**
     * A presence-only filter ignores its request value, so there is nothing to
     * validate.
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }
}
