<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * An object attribute whose keys a request may select one by one with
 * `fields[TYPE]=<attr>.<key>`, once it declares `selectableKeys()`.
 */
interface SelectsKeysInterface extends FieldInterface
{
    /**
     * The declaration, or `null` when the field's keys are not selectable.
     */
    public function selectableKeys(): ?SelectableKeys;

    /**
     * The field's read value holding only the `$only` keys. An `extractUsing()` hook
     * receives `$only` as its fourth argument, and whatever it returns is still trimmed
     * to those keys.
     */
    public function serializeSelection(mixed $model, JsonApiRequestInterface $request, string $name, KeySelection $only): mixed;
}
