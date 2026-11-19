<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows whose column is null. Presence-only: sending the key applies the
 * condition and the value carried with it is ignored.
 */
final readonly class WhereNull implements \haddowg\JsonApi\Resource\Through\FiltersThrough, \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    use \haddowg\JsonApi\Resource\Through\ResolvesThrough;
    public function __construct(
        public string $key,
        public string $column,
        public bool $deprecated = false,
        public ?string $description = null,
        public ?string $through = null,
        public ?\haddowg\JsonApi\Resource\Through\RelationPath $resolvedThrough = null,
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
     * A copy reading the column on the type a path of relations reaches, named by their API
     * names: `->through('campaign')`, or `->through('campaign.advertiser')` for several
     * hops. Only rows whose relations reach a row are considered, so a missing relation
     * never matches.
     */
    public function through(string $path): self
    {
        return $this->rebuilt(['through' => \haddowg\JsonApi\Resource\Through\ThroughPaths::checked($path), 'resolvedThrough' => null]);
    }

    /**
     * A copy marked deprecated in the OpenAPI document: its `filter[<key>]` parameter
     * carries `deprecated: true`. Documentation only.
     */
    public function deprecated(): self
    {
        return $this->rebuilt(['deprecated' => true]);
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
        return $this->rebuilt(['description' => $description]);
    }

    public function getDescription(): ?string
    {
        return $this->description;
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

    protected function throughLeaf(): array
    {
        return [\haddowg\JsonApi\Resource\Through\LeafKind::Value, 'column'];
    }

    public function leafValue(mixed $value): mixed
    {
        return $value;
    }
}
