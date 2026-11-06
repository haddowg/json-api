<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A field, filter or sort that can be marked deprecated for the OpenAPI document.
 *
 * Deprecation is documentation only. The projector marks the member `deprecated`, and
 * the server keeps accepting and rendering it exactly as before.
 */
interface Deprecatable
{
    /**
     * Whether the member is declared deprecated.
     */
    public function isDeprecated(): bool;
}
