<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * A date/time value must fall within an inclusive `[min, max]` range. Each
 * bound is a fixed `\DateTimeInterface` or a `\Closure` evaluated at validation
 * time. Closure bounds **do not round-trip to JSON Schema**.
 */
final readonly class Between implements Constraint
{
    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $min
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $max
     */
    public function __construct(
        public \DateTimeInterface|\Closure $min,
        public \DateTimeInterface|\Closure $max,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
