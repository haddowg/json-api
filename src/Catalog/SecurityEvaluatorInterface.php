<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

/**
 * Judges, without performing an operation, whether a caller may perform it. The
 * integration implements it with its own security layer, so a client can be shown only
 * the operations its user could use.
 */
interface SecurityEvaluatorInterface
{
    /**
     * {@see SecurityVerdict::Depends} when the rule needs the resource the operation
     * acts on, which is the usual answer for a {@see SecurityLevel::Entity} rule.
     *
     * @param object|null $user the caller, or `null` for an anonymous one
     */
    public function evaluate(OperationDefinition $operation, ?object $user = null): SecurityVerdict;
}
