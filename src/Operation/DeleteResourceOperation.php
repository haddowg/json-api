<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

/**
 * `DELETE /articles/1` — delete a single resource. Has no request body.
 */
final readonly class DeleteResourceOperation implements \haddowg\JsonApi\Operation\JsonApiOperationInterface
{
    public function __construct(
        private Target $target,
        private QueryParameters $queryParameters,
        private OperationContext $context,
    ) {}

    public function target(): Target
    {
        return $this->target;
    }

    public function queryParameters(): QueryParameters
    {
        return $this->queryParameters;
    }

    public function context(): OperationContext
    {
        return $this->context;
    }
}
