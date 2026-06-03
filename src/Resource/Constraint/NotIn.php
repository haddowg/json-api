<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Value must NOT be one of an enumerated set (JSON Schema `not: { enum }`).
 *
 * @template T
 */
final readonly class NotIn implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    /**
     * @var list<T>
     */
    public array $values;

    /**
     * @param list<T> $values
     */
    public function __construct(
        array $values,
        public Context $context = new Context(),
    ) {
        $this->values = $values;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
