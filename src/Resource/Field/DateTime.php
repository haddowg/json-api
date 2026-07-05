<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;

/**
 * An ISO-8601 date-time attribute (with timezone). Serializes a
 * `\DateTimeInterface` to a string in {@see $format}; hydrates a string back to
 * a `\DateTimeImmutable`.
 *
 * `before()` / `after()` / `between()` accept a fixed `\DateTimeInterface` or a
 * `\Closure` evaluated at validation time; closure bounds do not round-trip to
 * JSON Schema.
 *
 * Non-final by design: {@see Date} and {@see Time} extend it.
 */
class DateTime extends AbstractAttribute
{
    protected string $format = \DateTimeInterface::ATOM;

    protected ?string $useTimezone = null;

    /**
     * Overrides the serialization format string.
     *
     * @return static
     */
    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     * @return static
     */
    public function before(\DateTimeInterface|\Closure $bound): static
    {
        return $this->addConstraint(new Before($bound, $this->currentContext()));
    }

    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     * @return static
     */
    public function after(\DateTimeInterface|\Closure $bound): static
    {
        return $this->addConstraint(new After($bound, $this->currentContext()));
    }

    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $min
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $max
     * @return static
     */
    public function between(\DateTimeInterface|\Closure $min, \DateTimeInterface|\Closure $max): static
    {
        return $this->addConstraint(new Between($min, $max, $this->currentContext()));
    }

    /**
     * Converts hydrated values into the given timezone before storing.
     *
     * @return static
     */
    public function useTimezone(string $timezone): static
    {
        $this->useTimezone = $timezone;

        return $this;
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
