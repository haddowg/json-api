<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Passes if the value satisfies **at least one** of the wrapped alternatives
 * (Symfony's `AtLeastOneOf`; JSON Schema `anyOf`). Each alternative is itself a
 * constraint — use a {@see Sequentially} for an alternative made of several rules.
 * The alternatives share this constraint's {@see Context}.
 */
final readonly class AtLeastOneOf implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    /**
     * @var list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public array $constraints;

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints the alternatives
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
