<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A to-one relationship backed by a foreign key on the owning model
 * (`belongsTo`).
 */
class BelongsTo extends AbstractRelation
{
    public function isToMany(): bool
    {
        return false;
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        return $this->buildToOne($this->relatedValue($model, $request, $this->name), $request, $resolver);
    }
}
