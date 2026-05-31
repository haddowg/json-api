<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

/**
 * `GET /articles/1/author` — fetch the related resource(s) behind a relationship.
 * Has no request body.
 */
final readonly class FetchRelatedOperation implements JsonApiOperation
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
