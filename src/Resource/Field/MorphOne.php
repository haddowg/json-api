<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The to-one inverse of a polymorphic to-one: the related row whose `MorphTo` points at
 * the parent, as Laravel's `morphOne` reads it. It builds the same {@see HasOne} as
 * `HasOne::make($name, $type, $morphTo)`, and warm-up refuses it unless `$morphTo` names
 * a `MorphTo` of the related type.
 *
 *     MorphOne::make('latestNote', 'notes', 'owner'),
 */
final class MorphOne
{
    private function __construct() {}

    public static function make(string $name, string $type, string $morphTo): HasOneBuilder
    {
        return HasOneBuilder::morphOne($name, $type, $morphTo);
    }
}
