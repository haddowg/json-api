<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship as OutputToMany;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A polymorphic to-many relationship (`morphToMany`): the collection's members
 * may each be one of several declared types, passed as the mandatory list to
 * {@see make()}; each member's serializer is resolved at runtime by its own
 * `getType()` — the to-many parallel of {@see MorphTo}. The mixed-type members
 * are rendered by binding a {@see PolymorphicSerializer} that resolves and
 * delegates per member.
 */
final class MorphToMany extends AbstractRelation
{
    use DeclaresPolymorphicTypes;

    public function isToMany(): bool
    {
        return true;
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        $relationship = OutputToMany::create();

        // One decorator drives every member: it resolves the per-member serializer
        // (matching the member's own type against a declared one) and delegates, so
        // a mixed-type collection renders correct per-member type/id/attributes
        // without any transformer or ToManyRelationship change.
        $serializer = new PolymorphicSerializer(function (mixed $object) use ($resolver): SerializerInterface {
            return $this->resolveSerializer($object, $resolver)
                ?? throw new \LogicException(\sprintf('No declared type of the "%s" relationship serializes the related object.', $this->name));
        });

        if ($this->shouldDeferLinkage($model, $resolver)) {
            $relationship
                ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->name), $serializer)
                ->omitDataWhenNotIncluded();
        } else {
            $relationship->setData($this->relatedValue($model, $request, $this->name), $serializer);
        }

        // Convention links, the relationship-meta hook (its first consumer is the
        // countable `meta.total`, core ADR 0057 — so a countable polymorphic to-many
        // named in `?withCount` renders its cardinality), identifier meta, and the
        // Relationship-Queries pagination links — the shared to-many tail.
        $this->finalizeToMany($relationship, $model, $request, $resolver);

        return $relationship;
    }
}
