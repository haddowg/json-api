<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows where a value reached through a dotted relationship path is any of a
 * list of values: {@see WhereThrough}'s EXISTS-ANY traversal with {@see WhereIn}'s set
 * at the leaf. `WhereThroughIn::make('advertiser', 'campaign.advertiser')` answers
 * `filter[advertiser]=4,7` with the rows whose campaign's advertiser is 4 or 7. A data
 * layer translates it to one correlated EXISTS whose leaf is an IN over the list, never
 * one EXISTS per value. An empty list matches nothing.
 *
 * Its value is always a list ({@see AcceptsList}): `filter[<key>]=a,b` and
 * `filter[<key>][]=a&filter[<key>][]=b` both work unless the builder declares
 * `acceptsList(delimited: false)`, and every member is validated on its own against the
 * declared value constraints.
 *
 * The key and path follow {@see WhereThrough}'s rules. The path may end on a field, or,
 * once a {@see ThroughPathResolver} has resolved it, on a monomorphic relationship
 * (`campaign.advertiser`, `templates`), and then compares the related resource's id:
 * {@see storageValues()} decodes each wire id through the related type's encoder, and a
 * filter declaring no constraints takes the related id's format.
 */
final readonly class WhereThroughIn implements \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\ExemplifiedFilter, \haddowg\JsonApi\Resource\Filter\AcceptsList, \haddowg\JsonApi\Resource\Filter\TraversesPath
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints, each member validated against them
     */
    public function __construct(
        public string $key,
        public string $path,
        public ListValue $list,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public bool $deprecated = false,
        public ?ThroughPath $resolved = null,
    ) {}

    /**
     * Single-argument form uses the dotted path as both the wire key and the
     * traversal path; supply a second argument to override the key
     * (`make('advertiser', 'campaign.advertiser')`).
     */
    public static function make(string $key, ?string $path = null): WhereThroughInBuilder
    {
        return WhereThroughInBuilder::make($key, $path);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function listValue(): ListValue
    {
        return $this->list;
    }

    /**
     * The declared value constraints, or, for a path resolved to a relationship leaf
     * that declares none, the related id's format ({@see ThroughPath::idConstraints()}).
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return $this->constraints === [] && $this->resolved !== null ? $this->resolved->idConstraints() : $this->constraints;
    }

    public function throughPath(): string
    {
        return $this->path;
    }

    public function resolvedPath(): ?ThroughPath
    {
        return $this->resolved;
    }

    public function withResolvedPath(ThroughPath $path): static
    {
        return new self($this->key, $this->path, $this->list, $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated, $path);
    }

    /**
     * The values a data layer puts in the leaf's IN list: the members of the requested
     * value ({@see ListValue::members()}), each decoded to the related storage key when
     * the path ends on a relationship.
     *
     * @return list<mixed>
     *
     * @throws \haddowg\JsonApi\Exception\FilterValueInvalid when a relationship leaf's id cannot be decoded
     */
    public function storageValues(mixed $value): array
    {
        $members = $this->list->members($value);
        if ($this->resolved === null || !$this->resolved->endsOnRelation()) {
            return $members;
        }

        $resolved = $this->resolved;

        return \array_map(fn(mixed $member): mixed => $resolved->storageValue($this->key, $member), $members);
    }
}
