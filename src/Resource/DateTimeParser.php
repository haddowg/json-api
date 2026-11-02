<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * Parses a client-supplied date, date-time or time string into a `\DateTimeImmutable`,
 * refusing a value that only names a real instant after PHP rolls it over.
 *
 * `new \DateTimeImmutable('2026-02-30')` answers 2 March with no exception, recording
 * the overflow as a parse warning. This parser accepts exactly the strings the
 * constructor accepts and rejects any that raise a warning, so `2026-02-30`,
 * `2026-04-31T10:00Z` and `2026-02-28T24:00:00Z` are invalid rather than silently
 * moved to the next day. The date filters and the date-time attribute casts share it,
 * and an adapter that reads client dates itself uses it too, so every path agrees on
 * what a valid date is.
 */
final class DateTimeParser
{
    /**
     * @throws \InvalidArgumentException when the string does not parse, or parses only
     *                                   by rolling an impossible date or time over
     */
    public static function parse(string $value, ?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        try {
            $date = new \DateTimeImmutable($value, $timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && $errors['warning_count'] > 0) {
            throw new \InvalidArgumentException(\sprintf(
                'Failed to parse time string (%s): %s',
                $value,
                \implode('; ', $errors['warnings']),
            ));
        }

        return $date;
    }

    /**
     * Like {@see parse()}, but answers `null` instead of throwing, and for anything
     * other than a non-empty string.
     */
    public static function tryParse(mixed $value, ?\DateTimeZone $timezone = null): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return self::parse($value, $timezone);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
