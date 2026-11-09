<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortFamilyInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;

/**
 * Validates how a resource type's to-many relations order their members **at boot /
 * container warm-up**, so a mistake fails at `cache:clear` or deploy instead of as a
 * 500 on the first request that reads the relation.
 *
 * A relation's {@see DefaultSortedRelationInterface::defaultSort()} keys must each
 * name a sort its vocabulary ({@see RelationSorts::vocabulary()}) accepts, and that
 * vocabulary includes the related type's sorts, which only a resolver can reach. A
 * relation builder rejects what it can see alone when it builds; this checks the rest.
 * A host invokes it once per registered resource, beside the
 * {@see EagerLoadValidator}.
 */
final class RelationOrderValidator
{
    public function __construct(private readonly SerializerResolverInterface $resolver) {}

    /**
     * Validates every relation `$serializer` declares for the resource `$type`. A
     * serializer that is not an {@see AbstractResource} declares no relations here and
     * is a no-op.
     *
     * @throws \LogicException when a default sort key names no sort the relation accepts
     */
    public function validate(string $type, SerializerInterface $serializer): void
    {
        if (!$serializer instanceof AbstractResource) {
            return;
        }

        foreach ($serializer->allFields() as $field) {
            if ($field instanceof RelationInterface) {
                $this->validateDefaultSort($type, $field);
            }
        }
    }

    private function validateDefaultSort(string $type, RelationInterface $relation): void
    {
        if (!$relation instanceof DefaultSortedRelationInterface || $relation->defaultSort() === []) {
            return;
        }

        $vocabulary = RelationSorts::vocabulary($relation, $this->resolver);
        foreach ($relation->defaultSort() as $directive) {
            $key = $directive->sort->key();
            if (SortMatcher::recognises($vocabulary, $key)) {
                continue;
            }

            $accepted = \array_map(
                static fn(\haddowg\JsonApi\Resource\Sort\SortInterface $sort): string => $sort instanceof SortFamilyInterface ? $sort->key() . '.*' : $sort->key(),
                $vocabulary,
            );

            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" declares a defaultSort() on "%s", which is not a sort the relation accepts. %s',
                $relation->name(),
                $type,
                $key,
                $accepted === [] ? 'It accepts no sorts.' : 'It accepts: ' . \implode(', ', $accepted) . '.',
            ));
        }
    }
}
