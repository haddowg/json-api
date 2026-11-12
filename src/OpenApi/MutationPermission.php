<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Whether a relation lets one kind of relationship mutation through: always, for some
 * requests only, or never. Read by the error coverage from the relation's definition.
 *
 * @internal
 */
enum MutationPermission
{
    case Allowed;
    case Conditional;
    case Never;
}
