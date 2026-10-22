<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Field\Internal\TimezoneName;

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

    protected ?string $outputTimezone = null;

    public function build(): DateTime
    {
        return new DateTime($this->fieldState(), $this->format, $this->useTimezone, $this->outputTimezone);
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
     * Moves hydrated values into the given timezone before storing.
     *
     * @throws \InvalidArgumentException when PHP does not recognise `$timezone`
     *
     * @return static
     */
    public function inputTimezone(string $timezone): static
    {
        $this->useTimezone = TimezoneName::assertValid($timezone, 'inputTimezone');

        return $this;
    }

    /**
     * Moves serialized values into the given timezone before formatting. The domain
     * value is copied first, so a mutable `\DateTime` is never changed. Overrides
     * the server's default output zone for this field, and is the only way to
     * convert a `Date` or `Time`.
     *
     * @throws \InvalidArgumentException when PHP does not recognise `$timezone`
     *
     * @return static
     */
    public function outputTimezone(string $timezone): static
    {
        $this->outputTimezone = TimezoneName::assertValid($timezone, 'outputTimezone');

        return $this;
    }

    /**
     * Moves hydrated values into the given timezone before storing.
     *
     * @deprecated use {@see inputTimezone()}, which does the same thing
     *
     * @return static
     */
    public function useTimezone(string $timezone): static
    {
        return $this->inputTimezone($timezone);
    }
}
