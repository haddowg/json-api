<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * Sorts by a single column. The most common sort; schemas auto-derive one of
 * these for every field that declared `->sortable()`, carrying the field's
 * description.
 */
final readonly class SortByField implements DescribedSort, \haddowg\JsonApi\Resource\Deprecatable
{
    public function __construct(
        public string $key,
        public string $column,
        public bool $deprecated = false,
        public ?string $description = null,
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
     * A copy marked deprecated in the OpenAPI document: the key is listed under the
     * `sort` parameter's `x-deprecated-sorts`. Documentation only.
     */
    public function deprecated(): self
    {
        return new self($this->key, $this->column, true, $this->description);
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    /**
     * A copy carrying a description of the order this key sorts by, surfaced on the
     * `sort` parameter of the OpenAPI document.
     */
    public function describedAs(string $description): self
    {
        return new self($this->key, $this->column, $this->deprecated, $description);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
