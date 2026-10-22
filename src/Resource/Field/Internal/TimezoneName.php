<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

/**
 * Checks a timezone name when it is configured, so a typo fails while the field or
 * server is being built rather than as a 500 on the first render.
 *
 * @internal
 */
final class TimezoneName
{
    /**
     * @throws \InvalidArgumentException when PHP does not recognise `$timezone`
     */
    public static function assertValid(string $timezone, string $setting): string
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf(
                '%s() was given "%s", which is not a timezone PHP recognises.',
                $setting,
                $timezone,
            ));
        }

        return $timezone;
    }
}
