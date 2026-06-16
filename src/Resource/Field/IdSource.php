<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * How core sources a create's id when the client supplies none, set on the
 * {@see Id} field by {@see Id::generated()} / {@see Id::generateUsing()}. The
 * absence of a source (the field's default) means store-provided: core sets
 * nothing and the store/DB assigns the id.
 *
 * @internal
 */
enum IdSource
{
    /**
     * Core mints the id from the declared format ({@see IdFormat}).
     */
    case Format;

    /**
     * Core mints the id from a closure supplied to {@see Id::generateUsing()}.
     */
    case Closure;
}
