<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A to-many relationship (`hasMany`): a collection of related models — the built,
 * readonly value object the engine walks. Authors declare one with {@see make()},
 * which returns a mutable {@see HasManyBuilder}; the resource **builds** it into
 * this value object before use.
 *
 * Non-final by design: {@see BelongsToMany} extends it.
 */
readonly class HasMany extends AbstractRelationValue
{
    /**
     * Declares a to-many relation to `$type`. `$foreignKey` names the field or to-one
     * relation of `$type` that holds the parent's id: the relation then reads its members
     * as the related rows whose foreign key is the parent's id, whatever stores the parent.
     * Leave it out to read the members the parent model holds.
     */
    public static function make(string $name, string $type, ?string $foreignKey = null): HasManyBuilder
    {
        return HasManyBuilder::make($name, $type, $foreignKey);
    }

    public function isToMany(): bool
    {
        return true;
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        return $this->buildToMany($model, $request, $resolver);
    }
}
