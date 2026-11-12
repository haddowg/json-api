<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows that have a related record on the named relationship (optionally
 * narrowed by a nested filter). Data-layer-specific; core ships the metadata,
 * adapters interpret the relationship traversal.
 */
final readonly class WhereHas implements \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    public function __construct(
        public string $key,
        public string $relationship,
        public bool $deprecated = false,
        public ?string $description = null,
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
     * A copy marked deprecated in the OpenAPI document: its `filter[<key>]` parameter
     * carries `deprecated: true`. Documentation only.
     */
    public function deprecated(): self
    {
        return new self($this->key, $this->relationship, true, $this->description);
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    /**
     * A copy carrying a description for its `filter[<key>]` parameter in the OpenAPI
     * document, in place of the generic one. The document still says the filter applies
     * when present and ignores its value.
     */
    public function describedAs(string $description): self
    {
        return new self($this->key, $this->relationship, $this->deprecated, $description);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Always presence-triggered: the relationship's existence decides the match, so
     * whatever value the request carries with the key is ignored.
     */
    public function isPresenceTriggered(): bool
    {
        return true;
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
