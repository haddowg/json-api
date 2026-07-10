<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;

/**
 * The mutable **field builder** for an ISO-8601 date-time attribute. Adds the
 * format / timezone / temporal-bound helpers on top of the common
 * {@see AbstractFieldBuilder} surface; {@see build()} freezes it into a readonly
 * {@see DateTime} value object.
 *
 * `before()` / `after()` / `between()` accept a fixed `\DateTimeInterface` or a
 * `\Closure` evaluated at validation time; closure bounds do not round-trip to
 * JSON Schema.
 *
 * Non-final by design: {@see DateBuilder} and {@see TimeBuilder} extend it to
 * preset a date-only / time-only serialization format.
 */
class DateTimeBuilder extends AbstractFieldBuilder
{
    protected string $format = \DateTimeInterface::ATOM;

    protected ?string $useTimezone = null;

    public function build(): DateTime
    {
        return new DateTime($this->fieldState(), $this->format, $this->useTimezone);
    }

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
}
