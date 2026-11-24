<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

/**
 * A rule an operation enforces besides its primary one: another type's rule for one of
 * that type's own operations. A to-many related or relationship read, for one, is refused
 * when the caller may not list a type it relates to, so it carries each related type's
 * collection read rule as a gate with `$operation` set to `FetchCollection`.
 *
 * The operation is refused when any one of its gates refuses the caller, so a
 * {@see SecurityEvaluatorInterface} judges each on its own.
 */
final readonly class AdditionalGate
{
    /**
     * @param string           $type       the type whose rule it is
     * @param OperationKind    $operation  the operation of `$type` the rule guards
     * @param string|bool|null $expression the rule as the integration declares it
     * @param SecurityLevel    $level      what the rule needs to decide, as for a primary rule
     */
    public function __construct(
        public string $type,
        public OperationKind $operation,
        public string|bool|null $expression,
        public SecurityLevel $level = SecurityLevel::Type,
    ) {}
}
