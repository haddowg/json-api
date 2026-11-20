<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\KeySelection;

/**
 * An opt-in capability of a hand-written {@see SerializerInterface} that renders a type
 * whose Resource declares `selectableKeys()` on an attribute. When `fields[TYPE]` narrows
 * such an attribute to some of its keys, core calls {@see getSelectedAttribute()} in place
 * of the attribute's callable, so the serializer can read only the selected keys.
 *
 * A serializer that does not implement it is still narrowed: core calls the attribute's
 * callable from {@see SerializerInterface::getAttributes()} as usual. Either way core
 * trims the value to the selection, as it does a field's `extractUsing()` hook.
 */
interface SelectsAttributeKeysInterface
{
    /**
     * The value of the attribute `$name`, holding at least the keys `$only` selects. Called
     * only for an attribute {@see SerializerInterface::getAttributes()} returned, so an
     * attribute it leaves out for this request stays out.
     */
    public function getSelectedAttribute(mixed $object, JsonApiRequestInterface $request, string $name, KeySelection $only): mixed;
}
