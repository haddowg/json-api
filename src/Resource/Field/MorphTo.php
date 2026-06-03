<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A polymorphic to-one relationship (`morphTo`): the related resource may be one
 * of several declared types. Use {@see types()} to declare the allowed inverse
 * types; the related object's serializer is resolved at runtime by its own
 * `getType()`.
 */
final class MorphTo extends AbstractRelation
{
    /**
     * Declares the allowed inverse types.
     *
     * @return static
     */
    public function types(string ...$types): static
    {
        return $this->type(...$types);
    }

    public function isToMany(): bool
    {
        return false;
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        $related = $this->relatedValue($model, $request, $this->name);
        $relationship = \haddowg\JsonApi\Schema\Relationship\ToOneRelationship::create();

        if ($related === null) {
            return $relationship;
        }

        // Resolve the serializer for whichever declared type can serialize the
        // related object: try each until one is registered.
        foreach ($this->relatedTypes as $type) {
            if ($resolver->hasSerializerFor($type)) {
                $serializer = $resolver->serializerFor($type);
                if ($serializer->getType($related) === $type) {
                    $relationship->setData($related, $serializer);

                    return $relationship;
                }
            }
        }

        return $relationship;
    }
}
