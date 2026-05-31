<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * A date/time value must be strictly after a bound. The bound is either a fixed
 * `\DateTimeInterface` or a `\Closure` evaluated at validation time.
 *
 * Closure bounds **do not round-trip to JSON Schema**; adapters evaluate them.
 */
final readonly class After implements Constraint
{
    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     */
    public function __construct(
        public \DateTimeInterface|\Closure $bound,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
