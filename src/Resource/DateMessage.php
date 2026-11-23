<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * The message refusing a value that is not the date, date-time or time it should be. A
 * date filter's bound and a temporal attribute's write share it, in core's own errors and
 * in each adapter's validation, so a client reads one wording for each kind of date.
 *
 * The subject is what the client sent the value as: a filter bound's parameter, such as
 * `filter[startDate][min]`, or an attribute's pointer below `/data/attributes`, such as
 * `publishedAt` or `schedule/startsAt`. The example is the field's or filter's declared
 * one when it has one, else a fixed one for the kind.
 */
final readonly class DateMessage
{
    public const string DATE_TIME_EXAMPLE = '2026-10-01T00:00:00Z';

    public const string DATE_EXAMPLE = '2026-10-01';

    public const string TIME_EXAMPLE = '09:42:00Z';

    private function __construct(
        private string $template,
        public string $example,
    ) {}

    /**
     * An RFC 3339 `date-time` with an offset.
     */
    public static function dateTime(?string $example = null): self
    {
        return new self('%s is not an RFC 3339 date-time with an offset, such as %s.', $example ?? self::DATE_TIME_EXAMPLE);
    }

    /**
     * A full date, or a date in the format a field writes when `$example` is in it.
     */
    public static function date(?string $example = null): self
    {
        return new self('%s is not a date like %s.', $example ?? self::DATE_EXAMPLE);
    }

    /**
     * An RFC 3339 `full-time` with an offset, or a time in the format a field writes when
     * `$example` is in it.
     */
    public static function time(?string $example = null): self
    {
        return new self('%s is not a time like %s.', $example ?? self::TIME_EXAMPLE);
    }

    /**
     * A date-time in a format no RFC 3339 keyword names, shown by a value in that format.
     */
    public static function dateTimeLike(string $example): self
    {
        return new self('%s is not a date-time like %s.', $example);
    }

    /**
     * The message for the value sent as `$subject`.
     */
    public function for(string $subject): string
    {
        return \sprintf($this->template, $subject, $this->example);
    }
}
