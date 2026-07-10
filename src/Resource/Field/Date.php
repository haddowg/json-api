<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A calendar-date attribute (`YYYY-MM-DD`) — the built, readonly value object the
 * engine walks. A {@see DateTime} specialised to a date-only serialization
 * format. Authors declare one with {@see make()}, which returns a mutable
 * {@see DateBuilder}.
 */
final readonly class Date extends DateTime
{
    public static function make(string $name): DateBuilder
    {
        return new DateBuilder($name);
    }
}
