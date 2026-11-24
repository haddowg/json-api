<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Through\LeafKind;
use haddowg\JsonApi\Resource\Through\RelationPath;
use haddowg\JsonApi\Resource\Through\ResolvesThrough;
use haddowg\JsonApi\Resource\Through\SortsThrough;
use haddowg\JsonApi\Resource\Through\ThroughPaths;

/**
 * Sorts by a single column. The most common sort; schemas auto-derive one of
 * these for every field that declared `->sortable()`, carrying the field's
 * description.
 *
 * With {@see through()} it sorts by a field of the type a path of to-one relations
 * reaches, and by `null` for a row whose relation holds nothing.
 */
final readonly class SortByField implements DescribedSort, \haddowg\JsonApi\Resource\Deprecatable, SortsThrough, KeysetSortInterface
{
    use ResolvesThrough;

    public function __construct(
        public string $key,
        public string $column,
        public bool $deprecated = false,
        public ?string $description = null,
        public ?string $through = null,
        public ?RelationPath $resolvedThrough = null,
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
     * A copy sorting by the column of the type a path of to-one relations reaches, named
     * by their API names: `->through('campaign')`, or `->through('campaign.advertiser')`.
     * The column names a field of that type. A row whose relation holds nothing sorts as
     * `null` would. A path through a to-many is refused when the sort is resolved.
     */
    public function through(string $path): self
    {
        return $this->rebuilt(['through' => ThroughPaths::checked($path), 'resolvedThrough' => null]);
    }

    /**
     * A copy marked deprecated in the OpenAPI document: the key is listed under the
     * `sort` parameter's `x-deprecated-sorts`. Documentation only.
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
     * A copy carrying a description of the order this key sorts by, surfaced on the
     * `sort` parameter of the OpenAPI document.
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
     * The value `$row` sorts by: its column, or through a resolved path the leaf column
     * of the row its relations reach, `null` when they reach none. A backed enum sorts by
     * its backing value.
     */
    public function keysetValue(mixed $row): mixed
    {
        return $this->valueOf($row);
    }

    /**
     * {@see keysetValue()}, with `$admit` narrowing each reached row to the ones its
     * type's scope admits, so a row its scope hides reads as `null`.
     *
     * `$read` reads a hop's rows instead of the model's value when it returns any.
     *
     * @param (\Closure(string, list<mixed>, ?\haddowg\JsonApi\Resource\Field\RelationInterface=): list<mixed>)|null $admit
     * @param (\Closure(mixed, string, ?\haddowg\JsonApi\Resource\Field\RelationInterface): (iterable<mixed>|null))|null $read
     */
    public function valueOf(mixed $row, ?\Closure $admit = null, ?\Closure $read = null): mixed
    {
        if ($this->through === null) {
            $value = Accessor::get($row, $this->column);
        } else {
            $path = $this->resolvedThrough ?? throw new \LogicException(\sprintf('Sort "%s" reaches through "%s", which has not been resolved; resolve it with a ThroughPathResolver before applying it.', $this->key, $this->through));
            $reached = $path->reach($row, $admit, $read);
            $value = $reached === [] ? null : Accessor::get($reached[0], $path->leafColumn());
        }

        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    protected function throughLeaf(): array
    {
        return [LeafKind::Sort, 'column'];
    }
}
