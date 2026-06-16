<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows whose column is not null (the filter value is truthy).
 */
final readonly class WhereNotNull implements \haddowg\JsonApi\Resource\Filter\FilterInterface
{
    public function __construct(
        public string $key,
        public string $column,
    ) {}

    public static function make(string $key, ?string $column = null): self
    {
        return new self($key, $column ?? $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * A presence-only filter carries no client value to validate.
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }
}
