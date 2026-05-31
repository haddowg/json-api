<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Outermost middleware: turns any throwable escaping the chain into a JSON:API
 * error document.
 *
 * A {@see JsonApiException} is rendered with its own errors and status via
 * {@see ErrorResponse::fromException()}. Any other {@see \Throwable} becomes a
 * generic 500. The 500 mapping mirrors `laravel-json-api/exceptions`: with the
 * `$debug` flag on, the throwable's message becomes the error `detail` and its
 * `{exception, file, line, trace}` go into the error object's `meta` (the
 * spec-faithful home — `source` locates request parts and there is no standard
 * trace member); with `$debug` off, neither leaks and `detail` is generic.
 *
 * It does **not** inspect the handler's return value for response value objects:
 * a PSR-15 handler can only return a PSR-7 response (the response VOs do not
 * implement `ResponseInterface`), so consumer VOs are rendered by
 * {@see \haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter}. A successful
 * PSR-7 response passes through unchanged.
 */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ServerInterface $server,
        private bool $debug = false,
        private ?LoggerInterface $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (JsonApiException $exception) {
            return ErrorResponse::fromException($exception)->toPsrResponse($this->server, $request);
        } catch (\Throwable $throwable) {
            $this->logger?->error($throwable->getMessage(), ['exception' => $throwable]);

            return ErrorResponse::fromErrors($this->internalServerError($throwable))
                ->toPsrResponse($this->server, $request);
        }
    }

    /**
     * Builds the single 500 error for an unexpected throwable, attaching debug
     * details only when the debug flag is set.
     */
    private function internalServerError(\Throwable $throwable): Error
    {
        if ($this->debug === false) {
            return new Error(status: '500', title: 'Internal Server Error');
        }

        return new Error(
            status: '500',
            code: $throwable->getCode() === 0 ? '' : (string) $throwable->getCode(),
            title: 'Internal Server Error',
            detail: $throwable->getMessage(),
            meta: [
                'exception' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => $this->formatTrace($throwable),
            ],
        );
    }

    /**
     * The throwable's stack trace as frames with call arguments stripped, the
     * same shape Laravel's exception handler emits in debug mode.
     *
     * @return list<array<string, mixed>>
     */
    private function formatTrace(\Throwable $throwable): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $frame) {
            unset($frame['args']);
            $frames[] = $frame;
        }

        return $frames;
    }
}
