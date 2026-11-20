<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereThroughIn} filter. Its value is a
 * delimited list unless {@see acceptsList()} says `delimited: false`. {@see build()}
 * freezes the accumulated state into the readonly {@see WhereThroughIn} value object.
 *
 * @deprecated Declare the filter on the related type's field with `->through()` instead:
 *             `WhereThroughIn::make('campaignName', 'campaign.name')` is
 *             `WhereIn::make('campaignName', 'name')->through('campaign')->acceptsList()`,
 *             and a path ending on a relation is `WhereIdIn::make($key)->through($path)`.
 *             The path names relations by API name and the column a field of the reached
 *             type; see the filters guide for the migration. A path continuing past a `pivot`
 *             segment needs exactly one registered resource type for the entity the pivot
 *             row's member reaches: an entity served by no type, or by several, can't migrate
 *             until one type owns it. Removed in the next major version.
 */
final class WhereThroughInBuilder implements FilterBuilderInterface
{
    use BuildsListValue;
    use BuildsEnumValue;
    use BuildsValueConstraints;

    private string $path;

    public function __construct(
        private string $key,
        ?string $path = null,
    ) {
        $this->path = $path ?? $key;
    }

    public static function make(string $key, ?string $path = null): self
    {
        return new self($key, $path);
    }

    public function build(): WhereThroughIn
    {
        return new WhereThroughIn($this->key, $this->path, $this->builtListValue(null) ?? ListValue::delimited(), $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated);
    }
}
