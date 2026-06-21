<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A to-one relationship backed by a foreign key on the owning model
 * (`belongsTo`).
 *
 * Non-final by design: {@see HasOne} extends it.
 */
class BelongsTo extends AbstractRelation
{
    /**
     * Eager by default: the foreign key sits on the owning model, so resolving the
     * linkage identifier is free (no query). {@see AbstractRelation::$dataOnlyWhenLoaded}.
     */
    protected bool $dataOnlyWhenLoaded = false;

    public function isToMany(): bool
    {
        return false;
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        return $this->buildToOne($model, $request, $resolver);
    }
}
