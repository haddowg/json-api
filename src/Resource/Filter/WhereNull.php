<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows whose column is null. Presence-only: sending the key applies the
 * condition and the value carried with it is ignored.
 */
final readonly class WhereNull implements \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\FilterInterface, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    public function __construct(
        public string $key,
        public string $column,
        public bool $deprecated = false,
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
     * A copy marked deprecated in the OpenAPI document: its `filter[<key>]` parameter
     * carries `deprecated: true`. Documentation only.
     */
    public function deprecated(): self
    {
        return new self($this->key, $this->column, true);
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    /**
     * Always presence-triggered: the column's nullness decides the match, so whatever
     * value the request carries with the key is ignored.
     */
    public function isPresenceTriggered(): bool
    {
        return true;
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
