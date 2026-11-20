<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * An object attribute whose keys a request may select one by one with
 * `fields[TYPE]=<attr>.<key>`, once it declares `nestedFieldset()`. A key whose field is
 * itself such an object, declaring `nestedFieldset()` too, can be narrowed further as
 * `<attr>.<key>.<key>`. An {@see ArrayList} of such objects is one too, whose only key is
 * {@see NestedFieldset::ITEMS}, as `<attr>.*.<key>`.
 */
interface DeclaresNestedFieldsetInterface extends FieldInterface
{
    /**
     * The declaration, or `null` when the field declares no nested fieldset.
     */
    public function nestedFieldsetKeys(): ?NestedFieldsetKeys;

    /**
     * The field that renders the member `$key` of the object: the child of that name, or,
     * for an open object, the field every undeclared member renders through. `null` when no
     * field would render it.
     */
    public function keyField(string $key): ?FieldInterface;

    /**
     * The field's read value holding only the `$fieldset` keys, each narrowed key holding only
     * the keys selected inside it. An `extractUsing()` hook receives `$fieldset` as its fourth
     * argument, and whatever it returns is still trimmed to the nested fieldset.
     */
    public function serializeNestedFieldset(mixed $model, JsonApiRequestInterface $request, string $name, NestedFieldset $fieldset): mixed;
}
