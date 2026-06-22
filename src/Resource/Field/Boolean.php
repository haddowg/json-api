<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A boolean attribute. Serializes/hydrates as `bool`.
 */
final class Boolean extends AbstractAttribute
{
    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (bool) $raw;
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return \is_bool($value) ? $value : (bool) $value;
    }
}
