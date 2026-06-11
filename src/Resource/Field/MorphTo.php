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
        $relationship = \haddowg\JsonApi\Schema\Relationship\ToOneRelationship::create();

        // A polymorphic relation resolves the serializer from the related
        // object's own type, so it must read the related value. Under the
        // load-aware policy, defer that read (and the data member) unless the
        // relationship is included — see AbstractRelation::shouldDeferLinkage().
        if ($this->shouldDeferLinkage($model, $resolver)) {
            $deferredSerializer = null;
            foreach ($this->relatedTypes as $type) {
                if ($resolver->hasSerializerFor($type)) {
                    $deferredSerializer = $resolver->serializerFor($type);

                    break;
                }
            }

            if ($deferredSerializer !== null) {
                $relationship
                    ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->name), $deferredSerializer)
                    ->omitDataWhenNotIncluded();
            }
        } else {
            $related = $this->relatedValue($model, $request, $this->name);
            if ($related !== null) {
                // Resolve the serializer for whichever declared type reports the
                // related object's own type (the shared per-relation rule).
                $serializer = $this->resolveSerializer($related, $resolver);
                if ($serializer !== null) {
                    $relationship->setData($related, $serializer);
                }
            }
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks($this->uriFieldName());
        }

        return $relationship;
    }
}
