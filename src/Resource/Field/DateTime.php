<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;

/**
 * An ISO-8601 date-time attribute (with timezone) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see DateTimeBuilder}; the resource **builds** it into this value
 * object before use. Serializes a `\DateTimeInterface` to a string in
 * {@see $format}; hydrates a string back to a `\DateTimeImmutable`.
 *
 * Non-final by design: {@see Date} and {@see Time} extend it.
 */
readonly class DateTime extends AbstractFieldValue
{
    public function __construct(
        FieldState $state,
        protected string $format = \DateTimeInterface::ATOM,
        protected ?string $useTimezone = null,
    ) {
        parent::__construct($state);
    }

    public static function make(string $name): DateTimeBuilder
    {
        return new DateTimeBuilder($name);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format($this->format);
        }

        return $raw;
    }

    /**
     * A non-string or empty value passes through unchanged (leniency shared with
     * the other attribute casts — type validity is a constraint/validation
     * concern, not the cast's). A non-empty string that {@see \DateTimeImmutable}
     * cannot parse — calendar-garbage (`1997-13-99`) or nonsense (`banana`) —
     * raises a typed {@see AttributeValueInvalid} (422 at
     * `/data/attributes/<name>`) rather than letting the raw parse `\Exception`
     * escape as an uncaught 500: the cast is the last gate before the value is
     * written onto the domain object.
     */
    protected function deserializeValue(mixed $value): mixed
    {
        if (!\is_string($value) || $value === '') {
            return $value;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new AttributeValueInvalid($this->name(), $e->getMessage());
        }

        if ($this->useTimezone !== null) {
            $date = $date->setTimezone(new \DateTimeZone($this->useTimezone));
        }

        return $date;
    }
}
