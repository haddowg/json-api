<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Applies a set of constraints to the value **in order, stopping at the first
 * failure** (Symfony's `Sequentially`). All must ultimately hold, so it
 * round-trips to JSON Schema by merging the wrapped constraints into the field's
 * own schema. The wrapped constraints share this constraint's {@see Context}.
 */
final readonly class Sequentially implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    /**
     * @var list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public array $constraints;

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     */
    public function __construct(
        array $constraints,
        public Context $context = new Context(),
    ) {
        $this->constraints = $constraints;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
