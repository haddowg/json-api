<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\ResolvingServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bridges the PSR-15 world to the operations layer: turns an incoming PSR-7
 * request into the matching {@see JsonApiOperationInterface}, hands it to an
 * {@see OperationHandler}, and encodes the returned response value object back to
 * a PSR-7 response.
 *
 * Routing supplies the {@see Target} as a request attribute keyed by
 * {@see Target::class}. The operation is then chosen by {@see OperationFactory}
 * from the HTTP method crossed with the shape of the target (whether it names a
 * relationship, and if so whether it is the relationship-linkage endpoint).
 *
 * An optional `$strictQueryParameters` hook lets the server run its up-front
 * query-parameter validation on this PSR-15 path too, so a `handle()` request
 * gets the same registry-aware checks {@see \haddowg\JsonApi\Server\Server::dispatch()}
 * runs: the strict family and sparse-fieldset checks, and the `?include` path
 * check. It throws a `400` JSON:API exception for a rejected parameter; `null`
 * (the default) disables it.
 *
 * An optional `$operationGate` hook runs on the built operation before the handler.
 * The server passes its scope check through it, which throws a `403` JSON:API
 * exception for a token lacking a scope the operation needs.
 */
final readonly class Psr7ToOperationHandlerAdapter implements RequestHandlerInterface
{
    /**
     * @param (\Closure(JsonApiRequestInterface): void)|null    $strictQueryParameters
     * @param (\Closure(JsonApiOperationInterface): void)|null $operationGate
     */
    public function __construct(
        private \haddowg\JsonApi\Operation\OperationHandlerInterface $handler,
        private ResolvingServerInterface $server,
        private OperationFactory $factory = new OperationFactory(),
        private ?\Closure $strictQueryParameters = null,
        private ?\Closure $operationGate = null,
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

        if ($this->strictQueryParameters !== null) {
            ($this->strictQueryParameters)($jsonApiRequest);
        }

        $context = new OperationContext($this->server, $request);

        $operation = $this->factory->fromRequest($jsonApiRequest, $target, $context);

        if ($this->operationGate !== null) {
            ($this->operationGate)($operation);
        }

        $response = \haddowg\JsonApi\Schema\Link\Internal\LinkRendering::describeResponse($this->handler->handle($operation), $operation, $this->server);

        return $response->toPsrResponse($this->server, $request);
    }
}
