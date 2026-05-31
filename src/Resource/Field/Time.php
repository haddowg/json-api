<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A wall-clock time attribute (`HH:MM:SS`). A {@see DateTime} specialised to a
 * time-only serialization format.
 */
final class Time extends DateTime
{
    protected string $format = 'H:i:s';
}
