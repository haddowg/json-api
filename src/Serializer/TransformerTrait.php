<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * Optional attribute-value formatting helpers (date/decimal) for serializers.
 *
 * Compose it onto any {@see SerializerInterface} implementation — you do not need
 * to extend {@see AbstractSerializer} (which simply `use`s this trait) to get
 * them. Helper methods are instance methods per the project convention (no
 * `static`; call sites use `$this->`).
 */
trait TransformerTrait
{
    /**
     * Transforms a value to a decimal with a precision of $precision.
     */
    public function toDecimal(mixed $value, int $precision = 12): float
    {
        if (\is_numeric($value) === false) {
            return 0.0;
        }

        return \round((float) $value, $precision);
    }

    /**
     * Transforms a DateTime object to an ISO 8601 compatible date string.
     *
     * If the $displayedTimeZone parameter is present then $dateTime will be converted to that time zone.
     */
    public function toIso8601Date(\DateTimeInterface $dateTime, ?\DateTimeZone $displayedTimeZone = null): string
    {
        if ($displayedTimeZone !== null) {
            $dateTime = \DateTimeImmutable::createFromInterface($dateTime)->setTimezone($displayedTimeZone);
        }

        return $dateTime->format('Y-m-d');
    }

    /**
     * Transforms a DateTime object to an ISO 8601 compatible date-time string.
     *
     * If the $displayedTimeZone parameter is present then $dateTime will be converted to that time zone.
     */
    public function toIso8601DateTime(\DateTimeInterface $dateTime, ?\DateTimeZone $displayedTimeZone = null): string
    {
        if ($displayedTimeZone !== null) {
            $dateTime = \DateTimeImmutable::createFromInterface($dateTime)->setTimezone($displayedTimeZone);
        }

        return $dateTime->format(\DateTime::ATOM);
    }

    /**
     * Transforms an SQL compatible date-time string to an ISO 8601 compatible date-time string.
     */
    public function fromSqlToIso8601Time(string $string, ?\DateTimeZone $displayedTimeZone = null): string
    {
        $dateTime = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $string,
            $displayedTimeZone,
        );

        if ($dateTime === false) {
            return '';
        }

        return $dateTime->format(\DateTime::ATOM);
    }

    /**
     * Transforms an SQL compatible date-time string to an ISO 8601 compatible UTC date-time string.
     */
    public function fromSqlToUtcIso8601Time(string $string): string
    {
        return $this->fromSqlToIso8601Time($string, new \DateTimeZone('UTC'));
    }
}
