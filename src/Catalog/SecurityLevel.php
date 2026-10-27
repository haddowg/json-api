<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

/**
 * What an operation's security rule needs to reach a decision.
 */
enum SecurityLevel: string
{
    /** The operation has no security rule of its own. */
    case None = 'none';

    /** The rule decides from the caller alone, before any resource is loaded. */
    case Type = 'type';

    /** The rule needs the resource the operation acts on. */
    case Entity = 'entity';
}
