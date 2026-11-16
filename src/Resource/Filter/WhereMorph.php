<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\OpenApi\ParameterStyle;
use haddowg\JsonApi\OpenApi\QueryParameterShape;
use haddowg\JsonApi\OpenApi\Schema;

/**
 * Matches rows by the stored identifier of a polymorphic relation, a {@see \haddowg\JsonApi\Resource\Field\MorphTo}
 * or {@see \haddowg\JsonApi\Resource\Field\MorphToMany} of the type it filters. Its value
 * mirrors a resource identifier: `filter[subject][type]=rfps` matches every row whose
 * `subject` is an `rfps` resource, and `filter[subject][id]=812,813`, or the array form
 * `filter[subject][id][]=812`, narrows that to those ids.
 *
 * `type` is required and names exactly one of the relation's types. `id` is optional,
 * and each id is a wire id, decoded through that type's id encoder and validated against
 * its id format. Anything else is a `400` {@see FilterValueInvalid}.
 *
 * It compares only the stored identifier, so it works on a relation that has no related
 * endpoint and cannot be included. A data layer reads the relation from
 * {@see resolvedTarget()}, which a {@see ThroughPathResolver} sets, and the selection
 * from {@see selection()}: on a `MorphTo` it compares the stored type and id, and on a
 * `MorphToMany` it looks for a member of that type with one of those ids.
 */
final readonly class WhereMorph implements \haddowg\JsonApi\Resource\Deprecatable, DescribedFilter, ExemplifiedFilter, DescribesQueryParameter
{
    public function __construct(
        public string $key,
        public string $relation,
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public bool $deprecated = false,
        public ?MorphTarget $resolved = null,
    ) {}

    /**
     * `$relation` is the storage name of the polymorphic relation, its `column()`, and
     * defaults to the key.
     */
    public static function make(string $key, ?string $relation = null): WhereMorphBuilder
    {
        return WhereMorphBuilder::make($key, $relation);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Each id's constraints depend on the type the request names, so they are read from
     * {@see idConstraints()} once {@see requested()} has read the type.
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function hasExample(): bool
    {
        return $this->hasExample;
    }

    public function getExample(): mixed
    {
        return $this->example;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    /**
     * The relation resolved against the type the filter runs against, or `null` before
     * resolution.
     */
    public function resolvedTarget(): ?MorphTarget
    {
        return $this->resolved;
    }

    public function withResolvedTarget(MorphTarget $target): self
    {
        return new self($this->key, $this->relation, $this->description, $this->hasExample, $this->example, $this->deprecated, $target);
    }

    /**
     * The type and wire ids a requested value names, checked for shape and for a type the
     * relation declares.
     *
     * @return array{type: string, ids: list<string|int>|null}
     *
     * @throws FilterValueInvalid when the value is not `{type, id?}`, the type is not one of the relation's, or an id is not a single non-empty value
     * @throws \LogicException    when the filter has not been resolved
     */
    public function requested(mixed $value): array
    {
        $target = $this->target();
        if (!\is_array($value)) {
            throw new FilterValueInvalid($this->key, [\sprintf('Send filter[%1$s][type]=<type>, and optionally filter[%1$s][id]=<ids>.', $this->key)]);
        }

        $unknown = \array_diff(\array_map('\strval', \array_keys($value)), ['type', 'id']);
        if ($unknown !== []) {
            throw new FilterValueInvalid($this->key, [\sprintf('filter[%s] takes only the members type and id.', $this->key)]);
        }

        $type = $value['type'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw new FilterValueInvalid($this->key . '][type', [\sprintf('filter[%s][type] is required and names one type.', $this->key)]);
        }
        if (!\in_array($type, $target->types, true)) {
            throw new FilterValueInvalid($this->key . '][type', [\sprintf('"%s" is not a type filter[%s] matches. Use one of: %s.', $type, $this->key, \implode(', ', $target->types))]);
        }

        if (!\array_key_exists('id', $value)) {
            return ['type' => $type, 'ids' => null];
        }

        return ['type' => $type, 'ids' => $this->wireIds($value['id'])];
    }

    /**
     * The constraints each id of `$type` is validated against, the type's id format.
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     *
     * @throws \LogicException when the filter has not been resolved
     */
    public function idConstraints(string $type): array
    {
        return $this->target()->idConstraints($type);
    }

    /**
     * The stored identifiers a requested value selects, each id decoded to its storage
     * key.
     *
     * @throws FilterValueInvalid as {@see requested()} does, or when an id cannot be decoded
     * @throws \LogicException    when the filter has not been resolved
     */
    public function selection(mixed $value): MorphSelection
    {
        ['type' => $type, 'ids' => $ids] = $this->requested($value);
        if ($ids === null) {
            return new MorphSelection($type);
        }

        $target = $this->target();

        return new MorphSelection($type, \array_map(fn(string|int $id): mixed => $target->storageId($this->key, $type, $id), $ids));
    }

    /**
     * A `deepObject` mirroring a resource identifier: `type`, required, as an enum of the
     * relation's types, and `id` as a comma-separated string or an array of ids.
     */
    public function describeQueryParameter(Schema $valueSchema): QueryParameterShape
    {
        $type = Schema::ofType('string');
        if ($this->resolved !== null) {
            $type = $type->withEnum($this->resolved->types);
        }
        $id = Schema::ofType('string');

        return new QueryParameterShape(
            Schema::ofType('object')
                ->withProperties([
                    'type' => $type,
                    'id' => Schema::create()->withAnyOf([$id, Schema::ofType('array')->withItems($id)]),
                ])
                ->withRequired(['type'])
                ->withAdditionalProperties(false),
            ParameterStyle::DeepObject,
            true,
        );
    }

    private function target(): MorphTarget
    {
        return $this->resolved ?? throw new \LogicException(\sprintf(
            'WhereMorph filter "%s" has not been resolved against the type it filters; resolve it with a ThroughPathResolver before reading its value.',
            $this->key,
        ));
    }

    /**
     * @return list<string|int>
     */
    private function wireIds(mixed $id): array
    {
        if (\is_string($id)) {
            $id = \array_map('\trim', \explode(',', $id));
        }
        if (!\is_array($id) || !\array_is_list($id)) {
            throw new FilterValueInvalid($this->key . '][id', [\sprintf('Send the ids as filter[%1$s][id]=a,b or filter[%1$s][id][]=a.', $this->key)]);
        }

        $ids = [];
        foreach ($id as $member) {
            if ((!\is_string($member) && !\is_int($member)) || $member === '') {
                throw new FilterValueInvalid($this->key . '][id', ['Each id must be a single, non-empty value.']);
            }
            $ids[] = $member;
        }

        return $ids;
    }
}
