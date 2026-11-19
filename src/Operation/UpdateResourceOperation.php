<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * `PATCH /articles/1` — update a single resource. Carries the request body
 * holding the resource changes.
 */
final readonly class UpdateResourceOperation implements \haddowg\JsonApi\Operation\JsonApiOperationInterface
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

    /**
     * This operation carrying `$body` in place of its own, and `$context` when given.
     */
    public function withBody(JsonApiRequestInterface $body, ?OperationContext $context = null): self
    {
        return new self($this->target, $this->queryParameters, $context ?? $this->context, $body);
    }
}
