<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\DeclaresNestedFieldsetInterface;
use haddowg\JsonApi\Resource\Field\NestedFieldset;

/**
 * An attribute a request narrowed to some of its keys. The transformer renders it though
 * `fields[TYPE]` does not list the attribute's own name.
 *
 * @internal
 */
final readonly class NestedFieldsetAttribute
{
    /**
     * @param \Closure(mixed, JsonApiRequestInterface): mixed $owner the object the field reads its value from
     */
    public function __construct(
        private DeclaresNestedFieldsetInterface $field,
        private NestedFieldset $fieldset,
        private \Closure $owner,
    ) {}

    public function __invoke(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        $owner = ($this->owner)($model, $request);

        return $owner === null ? null : $this->field->serializeNestedFieldset($owner, $request, $name, $this->fieldset);
    }
}
