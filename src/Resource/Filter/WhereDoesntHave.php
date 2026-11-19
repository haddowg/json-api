<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows that lack a related record on the named relationship (the
 * negation of {@see WhereHas}).
 */
final readonly class WhereDoesntHave implements \haddowg\JsonApi\Resource\Through\FiltersThrough, \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    use \haddowg\JsonApi\Resource\Through\ResolvesThrough;
    public function __construct(
        public string $key,
        public string $relationship,
        public bool $deprecated = false,
        public ?string $description = null,
        public ?string $through = null,
        public ?\haddowg\JsonApi\Resource\Through\RelationPath $resolvedThrough = null,
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
     * A copy reading the relation on the type a path of relations reaches, named by their API
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

    protected function throughLeaf(): array
    {
        return [\haddowg\JsonApi\Resource\Through\LeafKind::Relation, 'relationship'];
    }

    public function leafValue(mixed $value): mixed
    {
        return $value;
    }
}
