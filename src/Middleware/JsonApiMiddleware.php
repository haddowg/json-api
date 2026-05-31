<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware;

use haddowg\JsonApi\Middleware\Internal\MiddlewareHandler;
use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Convenience aggregate that composes the JSON:API middleware suite into one
 * {@see MiddlewareInterface}, in the recommended order:
 *
 *   {@see ErrorHandlerMiddleware} (outermost)
 *     → {@see ContentNegotiationMiddleware}
 *       → {@see RequestBodyParsingMiddleware}
 *         → the wrapped handler.
 *
 * For consumers who do not want to manage ordering themselves. The three
 * building blocks remain independently constructable; this only wires them.
 */
final readonly class JsonApiMiddleware implements MiddlewareInterface
{
    private ErrorHandlerMiddleware $errorHandler;

    private ContentNegotiationMiddleware $contentNegotiation;

    private RequestBodyParsingMiddleware $bodyParsing;

    /**
     * @param string ...$supportedExtensions the extension URIs the negotiation
     *                                        step accepts (none → any `ext` rejected)
     */
    public function __construct(
        ServerInterface $server,
        bool $debug = false,
        ?LoggerInterface $logger = null,
        string ...$supportedExtensions,
    ) {
        $this->errorHandler = new ErrorHandlerMiddleware($server, $debug, $logger);
        $this->contentNegotiation = new ContentNegotiationMiddleware(...$supportedExtensions);
        $this->bodyParsing = new RequestBodyParsingMiddleware();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pipeline = new MiddlewareHandler(
            $this->errorHandler,
            new MiddlewareHandler(
                $this->contentNegotiation,
                new MiddlewareHandler($this->bodyParsing, $handler),
            ),
        );

        return $pipeline->handle($request);
    }
}
