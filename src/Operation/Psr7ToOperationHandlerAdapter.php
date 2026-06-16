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
 * strict query-parameter validation on this PSR-15 path too — so a `handle()`
 * request gets the same resource- and registry-aware family check
 * {@see \haddowg\JsonApi\Server\Server::dispatch()} runs. It throws
 * {@see \haddowg\JsonApi\Exception\QueryParamUnrecognized} (`400`) for an
 * unrecognized family; `null` (the default) disables it.
 */
final readonly class Psr7ToOperationHandlerAdapter implements RequestHandlerInterface
{
    /**
     * @param (\Closure(JsonApiRequestInterface): void)|null $strictQueryParameters
     */
    public function __construct(
        private \haddowg\JsonApi\Operation\OperationHandlerInterface $handler,
        private ResolvingServerInterface $server,
        private OperationFactory $factory = new OperationFactory(),
        private ?\Closure $strictQueryParameters = null,
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

        $response = $this->handler->handle($operation);

        return $response->toPsrResponse($this->server, $request);
    }
}
