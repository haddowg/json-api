<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A wall-clock time attribute (`HH:MM:SS`) — the built, readonly value object the
 * engine walks. A {@see DateTime} specialised to a time-only serialization
 * format. Authors declare one with {@see make()}, which returns a mutable
 * {@see TimeBuilder}.
 */
final readonly class Time extends DateTime
{
    public static function make(string $name): TimeBuilder
    {
        return new TimeBuilder($name);
    }
}
