<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a wall-clock time attribute (`HH:MM:SS`). A
 * {@see DateTimeBuilder} specialised to a time-only serialization format;
 * {@see build()} freezes it into a readonly {@see Time} value object.
 */
final class TimeBuilder extends DateTimeBuilder
{
    protected string $format = 'H:i:s';

    public function build(): Time
    {
        return new Time($this->fieldState(), $this->format, $this->useTimezone);
    }
}
