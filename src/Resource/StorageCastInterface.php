<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * Puts a hydrated attribute value in the form a data layer stores before core assigns
 * it to the model, such as a decimal column's string. A host sets one on the server
 * with {@see \haddowg\JsonApi\Server\Server::withStorageCast()}. Core asks it for each
 * attribute it writes to an object's member itself, including a flattened `on()`
 * attribute, and not for a value a `fillUsing()` hook writes or one nested in a
 * composite.
 */
interface StorageCastInterface
{
    /**
     * The value to assign to `$member` of `$model` in place of `$value`, the value
     * `$field` hydrated. Return `$value` to leave it unchanged.
     */
    public function forStorage(object $model, string $member, mixed $value, FieldInterface $field): mixed;
}
