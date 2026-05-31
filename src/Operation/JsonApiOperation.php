<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

/**
 * The common contract for every JSON:API operation: what endpoint it targets,
 * the query parameters in effect, and the ambient context (server + optional
 * originating HTTP request).
 *
 * Each HTTP verb has its own concrete operation carrying exactly the data it
 * needs; the five mutating verbs additionally expose a request body. Dispatching
 * on the operation type (rather than a verb enum) keeps handlers type-safe.
 */
interface JsonApiOperation
{
    public function target(): Target;

    public function queryParameters(): QueryParameters;

    public function context(): OperationContext;
}
