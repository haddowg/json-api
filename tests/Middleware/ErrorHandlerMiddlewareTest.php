<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class ErrorHandlerMiddlewareTest extends TestCase
{
    #[Test]
    public function successfulResponsePassesThroughUnchanged(): void
    {
        $response = (new Psr17Factory())->createResponse(201);
        $handler = new CallableHandler(static fn(): ResponseInterface => $response);

        $result = (new ErrorHandlerMiddleware(new StubServer()))
            ->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    #[Group('spec:errors')]
    public function jsonApiExceptionIsRenderedWithItsStatusAndError(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => throw new ResourceNotFound());

        $result = (new ErrorHandlerMiddleware(new StubServer()))
            ->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(404, $result->getStatusCode());
        self::assertStringStartsWith('application/vnd.api+json', $result->getHeaderLine('Content-Type'));

        $error = $this->firstError($result);
        self::assertSame('404', $error['status']);
        self::assertSame('The requested resource is not found!', $error['detail']);
    }

    #[Test]
    #[Group('spec:errors')]
    public function genericThrowableIsRedactedInProductionMode(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => throw new \RuntimeException('leaky secret detail'));

        $result = (new ErrorHandlerMiddleware(new StubServer(), debug: false))
            ->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $result->getStatusCode());

        $error = $this->firstError($result);
        self::assertSame('500', $error['status']);
        self::assertSame('Internal Server Error', $error['title']);
        self::assertArrayNotHasKey('detail', $error);
        self::assertArrayNotHasKey('meta', $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function genericThrowableIsVerboseInDebugMode(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => throw new \RuntimeException('leaky secret detail', 42));

        $result = (new ErrorHandlerMiddleware(new StubServer(), debug: true))
            ->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $result->getStatusCode());

        $error = $this->firstError($result);
        self::assertSame('500', $error['status']);
        self::assertSame('42', $error['code']);
        self::assertSame('leaky secret detail', $error['detail']);

        $meta = $error['meta'];
        self::assertIsArray($meta);
        self::assertSame(\RuntimeException::class, $meta['exception']);
        self::assertArrayHasKey('file', $meta);
        self::assertArrayHasKey('line', $meta);
        self::assertIsArray($meta['trace']);
    }

    #[Test]
    public function loggerReceivesUnexpectedThrowable(): void
    {
        $throwable = new \RuntimeException('boom');
        $handler = new CallableHandler(static fn(): ResponseInterface => throw $throwable);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('boom', ['exception' => $throwable]);

        (new ErrorHandlerMiddleware(new StubServer(), debug: false, logger: $logger))
            ->process(new ServerRequest('GET', '/'), $handler);
    }

    #[Test]
    public function jsonApiExceptionIsNotLogged(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => throw new ResourceNotFound());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        (new ErrorHandlerMiddleware(new StubServer(), debug: false, logger: $logger))
            ->process(new ServerRequest('GET', '/'), $handler);
    }

    /**
     * Decodes the response body and returns its first error object, asserting the
     * document shape along the way.
     *
     * @return array<string, mixed>
     */
    private function firstError(ResponseInterface $response): array
    {
        $decoded = \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('errors', $decoded);
        self::assertIsArray($decoded['errors']);
        self::assertArrayHasKey(0, $decoded['errors']);

        $error = $decoded['errors'][0];
        self::assertIsArray($error);

        /** @var array<string, mixed> $error */
        return $error;
    }
}
