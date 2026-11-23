<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * The message refusing a value that is not the date, date-time or time it should be. A
 * date filter's bound or value and a temporal attribute's write share it, in core's own
 * errors and in each adapter's validation, so a client reads one wording for each kind of
 * date. The error's `source` says which value it is, so the message names none.
 *
 * Each kind has a {@see $variant} a translation is keyed by, below the error's code, and
 * its {@see $example} is the error's `{example}` context, so a translated template shows
 * the same example. The example is the field's or filter's declared one when it has one,
 * else a fixed one for the kind.
 */
final readonly class DateMessage implements \Stringable
{
    public const string DATE_TIME_EXAMPLE = '2026-10-01T00:00:00Z';

    public const string DATE_EXAMPLE = '2026-10-01';

    public const string TIME_EXAMPLE = '09:42:00Z';

    /**
     * The variant of each kind, and the default template it renders.
     */
    private const array TEMPLATES = [
        'date_time' => 'This value is not an RFC 3339 date-time with an offset, such as {example}.',
        'date' => 'This value is not a date like {example}.',
        'time' => 'This value is not a time like {example}.',
        'formatted_date_time' => 'This value is not a date-time like {example}.',
    ];

    /**
     * @param 'date_time'|'date'|'time'|'formatted_date_time' $variant
     */
    private function __construct(
        public string $variant,
        public string $example,
    ) {}

    /**
     * An RFC 3339 `date-time` with an offset.
     */
    public static function dateTime(?string $example = null): self
    {
        return new self('date_time', $example ?? self::DATE_TIME_EXAMPLE);
    }

    /**
     * A full date, or a date in the format a field writes when `$example` is in it.
     */
    public static function date(?string $example = null): self
    {
        return new self('date', $example ?? self::DATE_EXAMPLE);
    }

    /**
     * An RFC 3339 `full-time` with an offset, or a time in the format a field writes when
     * `$example` is in it.
     */
    public static function time(?string $example = null): self
    {
        return new self('time', $example ?? self::TIME_EXAMPLE);
    }

    /**
     * A date-time in a format no RFC 3339 keyword names, shown by a value in that format.
     */
    public static function formattedDateTime(string $example): self
    {
        return new self('formatted_date_time', $example);
    }

    /**
     * The message one of these renders, read back, or `null` for any other text. An
     * adapter whose validator carries only a message string uses it to give the error its
     * translation key and context again.
     */
    public static function recognise(string $message): ?self
    {
        foreach (self::TEMPLATES as $variant => $template) {
            [$before, $after] = \explode('{example}', $template);
            if (\strlen($message) > \strlen($before . $after) && \str_starts_with($message, $before) && \str_ends_with($message, $after)) {
                return new self($variant, \substr($message, \strlen($before), -\strlen($after)));
            }
        }

        return null;
    }

    /**
     * The default template, its `{example}` placeholder unfilled.
     */
    public function template(): string
    {
        return self::TEMPLATES[$this->variant];
    }

    /**
     * The key a translation of this message is found under for an error of `$code`, such
     * as `ATTRIBUTE_VALUE_INVALID.date_time`, which an
     * {@see \haddowg\JsonApi\Schema\Error\ErrorDetailVariantResolverInterface} resolves.
     */
    public function detailVariant(string $code): string
    {
        return $code . '.' . $this->variant;
    }

    /**
     * The error context a template's placeholders are filled from.
     *
     * @return array{example: string}
     */
    public function context(): array
    {
        return ['example' => $this->example];
    }

    /**
     * The message with its example.
     */
    public function message(): string
    {
        return \str_replace('{example}', $this->example, $this->template());
    }

    public function __toString(): string
    {
        return $this->message();
    }
}
