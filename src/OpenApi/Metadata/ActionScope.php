<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Whether a custom action ({@see ActionMetadataInterface}) is mounted on the
 * resource collection or on an individual resource — the discriminator that
 * selects the action's path shape under the `-actions` segment (Slice 3):
 *
 *  - {@see Collection} → `/{uriType}/-actions/{path}`.
 *  - {@see Resource}   → `/{uriType}/{id}/-actions/{path}`.
 */
enum ActionScope: string
{
    case Collection = 'collection';

    case Resource = 'resource';
}
