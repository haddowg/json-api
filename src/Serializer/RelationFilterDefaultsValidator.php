<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DropsFilterDefaultsInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\HasDefaultValue;
use haddowg\JsonApi\Resource\Filter\HasRemovableDefault;
use haddowg\JsonApi\Resource\Filter\RelationFilters;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Validates the filter defaults a resource type's relations drop with
 * `withoutFilterDefaults()` **at boot / container warm-up**, so a mistake fails at
 * `cache:clear` or deploy instead of as a 500 on the first request that reads the
 * relation. The related type's filters are out of a relation builder's reach, so the
 * check runs here.
 *
 * Each key the relation names must be a filter declaring a default on a related type.
 * A polymorphic relation needs the key on at least one of its related types, since a
 * default may belong to only some of them. Every default the relation drops must be on
 * a {@see HasRemovableDefault} filter. A related type that isn't registered as an
 * {@see AbstractResource} declares no filters here and is skipped. A host invokes it
 * once per registered resource, beside the {@see RelationOrderValidator}.
 */
final class RelationFilterDefaultsValidator
{
    public function __construct(private readonly SerializerResolverInterface $resolver) {}

    /**
     * Validates every relation `$serializer` declares for the resource `$type`. A
     * serializer that is not an {@see AbstractResource} declares no relations here and
     * is a no-op.
     *
     * @throws \LogicException when a named key is no defaulted filter of any related type, or a dropped default belongs to a filter that cannot drop it
     */
    public function validate(string $type, SerializerInterface $serializer): void
    {
        if (!$serializer instanceof AbstractResource) {
            return;
        }

        foreach ($serializer->allFields() as $field) {
            if ($field instanceof DropsFilterDefaultsInterface) {
                $this->validateRelation($type, $field);
            }
        }
    }

    private function validateRelation(string $type, DropsFilterDefaultsInterface $relation): void
    {
        if (!$relation->dropsAllFilterDefaults() && $relation->droppedFilterDefaults() === []) {
            return;
        }

        $relatedFilters = $this->relatedFilters($relation);
        if ($relatedFilters === null) {
            return;
        }

        $defaulted = [];
        foreach ($relatedFilters as $filters) {
            foreach ($filters as $filter) {
                if (!$filter instanceof HasDefaultValue || !$filter->hasDefault()) {
                    continue;
                }
                $defaulted[$filter->key()] = true;
                if (RelationFilters::dropsDefault($relation, $filter->key()) && !$filter instanceof HasRemovableDefault) {
                    throw new \LogicException(\sprintf(
                        'Relation "%s" on resource type "%s" drops the default of filter "%s", but %s cannot drop its default. Implement %s on it.',
                        $relation->name(),
                        $type,
                        $filter->key(),
                        $filter::class,
                        HasRemovableDefault::class,
                    ));
                }
            }
        }

        foreach ($relation->droppedFilterDefaults() as $key) {
            if (isset($defaulted[$key])) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" declares withoutFilterDefaults() on "%s", which is not a filter declaring a default on %s. %s',
                $relation->name(),
                $type,
                $key,
                \count($relation->relatedTypes()) === 1 ? 'its related type' : 'any of its related types',
                $defaulted === [] ? 'No related filter declares a default.' : 'Defaults are declared on: ' . \implode(', ', \array_keys($defaulted)) . '.',
            ));
        }
    }

    /**
     * The built filters of each related type registered as an {@see AbstractResource},
     * or `null` when none is.
     *
     * @return non-empty-list<list<FilterInterface>>|null
     */
    private function relatedFilters(RelationInterface $relation): ?array
    {
        $filters = [];
        foreach ($relation->relatedTypes() as $related) {
            if (!$this->resolver->hasSerializerFor($related)) {
                continue;
            }
            $serializer = $this->resolver->serializerFor($related);
            if ($serializer instanceof AbstractResource) {
                $filters[] = $serializer->allFilters();
            }
        }

        return $filters === [] ? null : $filters;
    }
}
