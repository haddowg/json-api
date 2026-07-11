<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A to-one relationship backed by a foreign key on the owning model
 * (`belongsTo`) — the built, readonly value object the engine walks. Authors
 * declare one with {@see make()}, which returns a mutable {@see BelongsToBuilder};
 * the resource **builds** it into this value object before use.
 *
 * Non-final by design: {@see HasOne} extends it.
 */
readonly class BelongsTo extends AbstractRelationValue
{
    public static function make(string $name, string $type): BelongsToBuilder
    {
        return BelongsToBuilder::make($name, $type);
    }

    public function isToMany(): bool
    {
        return false;
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        return $this->buildToOne($model, $request, $resolver);
    }
}
