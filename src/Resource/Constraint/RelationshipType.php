<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Constrains a relationship's resource-identifier `type` member(s) to an
 * allowed set of JSON:API resource types. For a polymorphic relationship the
 * list carries every permitted inverse type.
 */
final readonly class RelationshipType implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    /**
     * @var list<string>
     */
    public array $types;

    /**
     * @param list<string> $types
     */
    public function __construct(
        array $types,
        public Context $context = new Context(),
    ) {
        $this->types = $types;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
