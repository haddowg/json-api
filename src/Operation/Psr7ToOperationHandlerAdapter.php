<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bridges the PSR-15 world to the operations layer: turns an incoming PSR-7
 * request into the matching {@see JsonApiOperation}, hands it to an
 * {@see OperationHandler}, and encodes the returned response value object back to
 * a PSR-7 response.
 *
 * Routing supplies the {@see Target} as a request attribute keyed by
 * {@see Target::class}. The operation is then chosen by
 * the HTTP method crossed with the shape of the target (whether it names a
 * relationship, and if so whether it is the relationship-linkage endpoint).
 */
final readonly class Psr7ToOperationHandlerAdapter implements RequestHandlerInterface
{
    public function __construct(
        private OperationHandler $handler,
        private ServerInterface $server,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $target = $request->getAttribute(Target::class);
        if ($target instanceof Target === false) {
            // Routing failed to attach a Target — a server-side wiring fault, not a
            // client error. Render a 500 ErrorResponse rather than throwing, so the
            // PSR-15 contract still yields a JSON:API response.
            return ErrorResponse::fromException(new ApplicationError())
                ->toPsrResponse($this->server, $request);
        }

        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);
        $query = QueryParameters::fromRequest($jsonApiRequest);
        $context = new OperationContext($this->server, $request);

        $operation = $this->createOperation($request->getMethod(), $target, $query, $context, $jsonApiRequest);

        $response = $this->handler->handle($operation);

        return $response->toPsrResponse($this->server, $request);
    }

    private function createOperation(
        string $method,
        Target $target,
        QueryParameters $query,
        OperationContext $context,
        JsonApiRequestInterface $body,
    ): JsonApiOperation {
        $hasRelationship = $target->hasRelationship();

        return match (\strtoupper($method)) {
            'GET' => match (true) {
                $hasRelationship === false => new FetchResourceOperation($target, $query, $context),
                $target->isRelationshipEndpoint => new FetchRelationshipOperation($target, $query, $context),
                default => new FetchRelatedOperation($target, $query, $context),
            },
            'POST' => $hasRelationship
                ? new AddToRelationshipOperation($target, $query, $context, $body)
                : new CreateResourceOperation($target, $query, $context, $body),
            'PATCH' => $hasRelationship
                ? new UpdateRelationshipOperation($target, $query, $context, $body)
                : new UpdateResourceOperation($target, $query, $context, $body),
            'DELETE' => $hasRelationship
                ? new RemoveFromRelationshipOperation($target, $query, $context, $body)
                : new DeleteResourceOperation($target, $query, $context),
            default => throw new ApplicationError(),
        };
    }
}
