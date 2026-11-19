<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * An object attribute whose keys a request may select one by one with
 * `fields[TYPE]=<attr>.<key>`, once it declares `selectableKeys()`. A key whose field is
 * itself such an object, declaring `selectableKeys()` too, can be narrowed further as
 * `<attr>.<key>.<key>`. An {@see ArrayList} of such objects is one too, whose only key is
 * {@see KeySelection::ITEMS}, as `<attr>.*.<key>`.
 */
interface SelectsKeysInterface extends FieldInterface
{
    /**
     * The declaration, or `null` when the field's keys are not selectable.
     */
    public function selectableKeys(): ?SelectableKeys;

    /**
     * The field that renders the member `$key` of the object: the child of that name, or,
     * for an open object, the field every undeclared member renders through. `null` when no
     * field would render it.
     */
    public function keyField(string $key): ?FieldInterface;

    /**
     * The field's read value holding only the `$only` keys, each narrowed key holding only
     * the keys selected inside it. An `extractUsing()` hook receives `$only` as its fourth
     * argument, and whatever it returns is still trimmed to the selection.
     */
    public function serializeSelection(mixed $model, JsonApiRequestInterface $request, string $name, KeySelection $only): mixed;
}
