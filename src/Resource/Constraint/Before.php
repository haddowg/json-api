<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * A date/time value must be strictly before a bound. The bound is either a
 * fixed `\DateTimeInterface` or a `\Closure` evaluated at validation time.
 *
 * Closure bounds **do not round-trip to JSON Schema** (the compiler emits a
 * `formatMinimum`/`formatMaximum` only for fixed bounds); adapters evaluate
 * the closure.
 */
final readonly class Before implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
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
