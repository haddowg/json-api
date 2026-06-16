<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The declared format of an {@see Id} field, recorded by the `uuid()` / `ulid()`
 * / `numeric()` / `pattern()` shortcuts. Only `Uuid` and `Ulid` are
 * self-generating, so only those are valid targets for {@see Id::generated()}.
 *
 * @internal
 */
enum IdFormat
{
    case Uuid;

    case Ulid;

    case Numeric;

    case Pattern;
}
