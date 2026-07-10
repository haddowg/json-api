<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a calendar-date attribute (`YYYY-MM-DD`). A
 * {@see DateTimeBuilder} specialised to a date-only serialization format;
 * {@see build()} freezes it into a readonly {@see Date} value object.
 */
final class DateBuilder extends DateTimeBuilder
{
    protected string $format = 'Y-m-d';

    public function build(): Date
    {
        return new Date($this->fieldState(), $this->format, $this->useTimezone);
    }
}
