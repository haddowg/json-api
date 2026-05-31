<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Applies a set of constraints to every item of an array (JSON Schema `items`).
 *
 * The wrapped constraints share this constraint's {@see Context}.
 */
final readonly class Each implements Constraint
{
    /**
     * @var list<Constraint>
     */
    public array $constraints;

    /**
     * @param list<Constraint> $constraints
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
