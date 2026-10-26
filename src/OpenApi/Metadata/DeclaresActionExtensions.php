<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Action metadata that declares OpenAPI vendor extensions (`x-…`) for the action's own
 * operation, such as an `x-workflows` link. The action's set is merged over the type-wide
 * {@see DeclaresOperationExtensions::extensions()}, so the action's value wins on a shared
 * key, the way {@see DeclaresOperationExtensions::operationExtensions()} works for a CRUD
 * operation.
 *
 * Names are normalised to the `x-` prefix (`workflows` emits `x-workflows`); a reserved
 * `x-oai-`/`x-oas-` name is rejected with an `\InvalidArgumentException`.
 *
 * A separate, opt-in contract rather than a method on {@see ActionMetadataInterface}, so an
 * existing implementation keeps working.
 */
interface DeclaresActionExtensions extends ActionMetadataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function extensions(): array;
}
