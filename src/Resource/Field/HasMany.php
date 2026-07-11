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
    public static function make(string $name, string $type): HasManyBuilder
    {
        return HasManyBuilder::make($name, $type);
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
