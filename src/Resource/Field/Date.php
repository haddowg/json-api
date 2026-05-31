<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A calendar-date attribute (`YYYY-MM-DD`). A {@see DateTime} specialised to a
 * date-only serialization format.
 */
final class Date extends DateTime
{
    protected string $format = 'Y-m-d';
}
