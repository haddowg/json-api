<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A to-many relationship (`hasMany`): a collection of related models.
 */
class HasMany extends AbstractRelation
{
    public function isToMany(): bool
    {
        return true;
    }

    /**
     * @return static
     */
    public function minItems(int $count): static
    {
        return $this->addConstraint(new MinItems($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function maxItems(int $count): static
    {
        return $this->addConstraint(new MaxItems($count, $this->currentContext()));
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        return $this->buildToMany($this->relatedValue($model, $request, $this->name), $request, $resolver);
    }
}
