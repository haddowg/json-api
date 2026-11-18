<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * Parses a date, date-time or time string into a `\DateTimeImmutable`, refusing a value
 * that only names a real instant after PHP rolls it over.
 *
 * `new \DateTimeImmutable('2026-02-30')` answers 2 March with no exception, recording
 * the overflow as a parse warning. {@see parse()} accepts the strings the constructor
 * accepts and rejects any that raise a warning, so `2026-02-30`, `2026-04-31T10:00Z`
 * and `2026-02-28T24:00:00Z` are invalid rather than silently moved to the next day.
 * That is lenient about shape (`last year` parses), so it suits values the server wrote.
 *
 * A request value is read with the strict forms instead, which accept only the shape
 * the document describes: {@see parseDateTime()} an RFC 3339 `date-time`,
 * {@see parseDate()} a `full-date` and {@see parseTime()} a `full-time`, each with the
 * same calendar check. The attribute casts and the date filters use them, and so does an
 * adapter that reads a client date itself, so every path agrees on what a valid date is.
 */
final class DateTimeParser
{
    /**
     * An RFC 3339 `date-time`: a full date, `T`, a time with optional fraction, and a
     * `Z` or `±hh:mm` offset. RFC 3339 lets `T` and `Z` be lower case.
     */
    private const DATE_TIME = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    /**
     * An RFC 3339 `full-date`.
     */
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * An RFC 3339 `full-time`: a time with optional fraction and a `Z` or `±hh:mm` offset.
     */
    private const TIME = '/^\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

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
     * Parses an RFC 3339 `date-time`, such as `2026-11-05T09:42:00Z` or
     * `2026-11-05T09:42:00.123+01:00`, in the offset it names.
     *
     * @throws \InvalidArgumentException when the string is not an RFC 3339 date-time or
     *                                   names an impossible date or time
     */
    public static function parseDateTime(string $value): \DateTimeImmutable
    {
        if (\preg_match(self::DATE_TIME, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an RFC 3339 date-time with an offset, such as 2026-11-05T09:42:00Z', $value));
        }

        return self::parse($value);
    }

    /**
     * Parses an RFC 3339 `full-date`, such as `2026-11-05`, as midnight in `$timezone`,
     * or in PHP's default zone when none is given.
     *
     * @throws \InvalidArgumentException when the string is not a full date or names an
     *                                   impossible day
     */
    public static function parseDate(string $value, ?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        if (\preg_match(self::DATE, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a full date, such as 2026-11-05', $value));
        }

        return self::parse($value, $timezone);
    }

    /**
     * Parses an RFC 3339 `full-time`, such as `09:42:00Z` or `09:42:00.5+01:00`, as that
     * time today in the offset it names.
     *
     * @throws \InvalidArgumentException when the string is not an RFC 3339 time with an
     *                                   offset or names an impossible time
     */
    public static function parseTime(string $value): \DateTimeImmutable
    {
        if (\preg_match(self::TIME, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an RFC 3339 time with an offset, such as 09:42:00Z', $value));
        }

        return self::parse($value);
    }

    /**
     * Parses a string written in the PHP date `$format`, accepting it only when it is
     * exactly what that format renders for the instant it names. The instant is the one
     * {@see parse()} reads when that renders back to the same string, so a value means
     * what it meant to the constructor, and otherwise the one the format reads.
     *
     * @throws \InvalidArgumentException when the string is not in `$format` or names an
     *                                   impossible date or time
     */
    public static function parseFormat(string $value, string $format, ?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format($format) !== $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not written in the format %s', $value, $format));
        }

        $read = self::tryParse($value, $timezone);

        return $read !== null && $read->format($format) === $value ? $read : $date;
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
