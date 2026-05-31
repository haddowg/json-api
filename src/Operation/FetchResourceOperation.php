<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

/**
 * `GET /articles` or `GET /articles/1` — fetch a resource collection or a single
 * resource. Has no request body.
 */
final readonly class FetchResourceOperation implements JsonApiOperation
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
