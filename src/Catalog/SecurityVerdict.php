<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

/**
 * What a {@see SecurityEvaluatorInterface} can say about a caller and an operation
 * without performing it.
 */
enum SecurityVerdict: string
{
    /** The caller may perform the operation. */
    case Allowed = 'allowed';

    /** The caller may not perform the operation on any resource. */
    case Denied = 'denied';

    /** The answer depends on the resource, so it is known only when the operation runs. */
    case Depends = 'depends';
}
