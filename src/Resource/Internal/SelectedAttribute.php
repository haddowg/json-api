<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\SelectsKeysInterface;

/**
 * An attribute a request narrowed to some of its keys. The transformer renders it though
 * `fields[TYPE]` does not list the attribute's own name.
 *
 * @internal
 */
final readonly class SelectedAttribute
{
    /**
     * @param \Closure(mixed, JsonApiRequestInterface): mixed $owner the object the field reads its value from
     */
    public function __construct(
        private SelectsKeysInterface $field,
        private KeySelection $only,
        private \Closure $owner,
    ) {}

    public function __invoke(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        $owner = ($this->owner)($model, $request);

        return $owner === null ? null : $this->field->serializeSelection($owner, $request, $name, $this->only);
    }
}
