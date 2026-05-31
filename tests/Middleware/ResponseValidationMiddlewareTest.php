<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use haddowg\JsonApi\Middleware\ResponseValidationMiddleware;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use haddowg\JsonApi\Tests\Double\StubServer;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

#[Group('spec:document-structure')]
final class ResponseValidationMiddlewareTest extends TestCase
{
    private function middleware(
        bool $throwOnViolation = true,
        ?AbstractLogger $logger = null,
    ): ResponseValidationMiddleware {
        return new ResponseValidationMiddleware(
            new StubServer(),
            new DocumentValidator(new VendoredSchemaProvider()),
            $throwOnViolation,
            $logger,
        );
    }

    private function jsonApiResponse(string $body, int $status = 200): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/vnd.api+json')
            ->withBody($factory->createStream($body));
    }

    private function handlerReturning(ResponseInterface $response): CallableHandler
    {
        return new CallableHandler(static fn(): ResponseInterface => $response);
    }

    #[Test]
    public function wellFormedResponseDocumentPassesThrough(): void
    {
        $response = $this->jsonApiResponse('{"data":{"type":"articles","id":"1"}}');

        $result = $this->middleware()->process(new ServerRequest('GET', '/articles/1'), $this->handlerReturning($response));

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('{"data":{"type":"articles","id":"1"}}', (string) $result->getBody());
    }

    #[Test]
    public function brokenOutgoingDocumentThrowsByDefault(): void
    {
        // Missing id on a response resource is invalid.
        $response = $this->jsonApiResponse('{"data":{"type":"articles"}}');

        $this->expectException(ResponseBodyInvalidJsonApi::class);

        $this->middleware()->process(new ServerRequest('GET', '/articles/1'), $this->handlerReturning($response));
    }

    #[Test]
    public function loggerOnlyModeLogsAndPassesThrough(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /**
             * @param array<mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $response = $this->jsonApiResponse('{"data":{"type":"articles"}}');

        $result = $this->middleware(throwOnViolation: false, logger: $logger)
            ->process(new ServerRequest('GET', '/articles/1'), $this->handlerReturning($response));

        self::assertSame(200, $result->getStatusCode());
        self::assertCount(1, $logger->messages);
    }

    #[Test]
    public function nonJsonApiResponseIsNotValidated(): void
    {
        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', 'text/html')
            ->withBody((new Psr17Factory())->createStream('<html>not json</html>'));

        $result = $this->middleware()->process(new ServerRequest('GET', '/'), $this->handlerReturning($response));

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function emptyBodyPassesThrough(): void
    {
        $response = (new Psr17Factory())->createResponse(204)->withHeader('Content-Type', 'application/vnd.api+json');

        $result = $this->middleware()->process(new ServerRequest('GET', '/'), $this->handlerReturning($response));

        self::assertSame(204, $result->getStatusCode());
    }
}
