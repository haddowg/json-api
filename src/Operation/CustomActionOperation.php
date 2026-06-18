<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * `POST /articles/1/-actions/publish` (resource scope) or
 * `POST /articles/-actions/import` (collection scope) — an author-defined,
 * non-CRUD action hanging off a resource type under the reserved `-actions`
 * segment.
 *
 * Carries the named {@see $action} and the HTTP {@see $method} that invoked it,
 * plus an optional request {@see $body} (present only when the action declares a
 * JSON:API document input; `null` for input-less or raw-bodied actions). The
 * {@see Target} distinguishes the two scopes: a resource-scope action carries an
 * `id` ({@see Target::hasId()} `true`), a collection-scope action does not.
 *
 * Being a {@see JsonApiOperationInterface}, it routes through
 * {@see \haddowg\JsonApi\Server\Server::dispatch()} unchanged — inheriting the
 * strict-query validation, the request-wide serving gate, and the single
 * {@see OperationHandlerInterface::handle()} contract.
 */
final readonly class CustomActionOperation implements JsonApiOperationInterface
{
    public function __construct(
        private Target $target,
        private QueryParameters $queryParameters,
        private OperationContext $context,
        private string $action,
        private string $method,
        private ?JsonApiRequestInterface $body = null,
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

    public function action(): string
    {
        return $this->action;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function body(): ?JsonApiRequestInterface
    {
        return $this->body;
    }
}
