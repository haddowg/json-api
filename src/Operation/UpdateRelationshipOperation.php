<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * `PATCH /articles/1/relationships/author` — replace a relationship's linkage
 * wholesale. Carries the request body holding the new linkage.
 */
final readonly class UpdateRelationshipOperation implements JsonApiOperation
{
    public function __construct(
        private Target $target,
        private QueryParameters $queryParameters,
        private OperationContext $context,
        private JsonApiRequestInterface $body,
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

    public function body(): JsonApiRequestInterface
    {
        return $this->body;
    }
}
