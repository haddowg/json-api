<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:fetching-data')]
final class Psr7ToOperationHandlerAdapterTest extends TestCase
{
    #[Test]
    public function getDispatchesAFetchResourceOperationAndEmitsAPsr7Response(): void
    {
        $handler = new RecordingOperationHandler(
            DataResponse::fromResource(new \stdClass(), new StubResource('articles', '1')),
        );
        $adapter = new Psr7ToOperationHandlerAdapter($handler, new StubServer());

        $request = (new ServerRequest('GET', '/articles/1'))
            ->withAttribute(Target::class, new Target('articles', '1'));

        $response = $adapter->handle($request);

        self::assertInstanceOf(FetchResourceOperation::class, $handler->received);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function postWithABodyDispatchesACreateResourceOperationWhoseBodySeesThePostedData(): void
    {
        $handler = new RecordingOperationHandler(
            DataResponse::fromResource(new \stdClass(), new StubResource('articles', '1')),
        );
        $adapter = new Psr7ToOperationHandlerAdapter($handler, new StubServer());

        $request = (new ServerRequest('POST', '/articles'))
            ->withAttribute(Target::class, new Target('articles'))
            ->withParsedBody(['data' => ['type' => 'articles', 'attributes' => ['title' => 'Hello']]]);

        $response = $adapter->handle($request);

        $operation = $handler->received;
        self::assertInstanceOf(CreateResourceOperation::class, $operation);
        self::assertSame('articles', $operation->body()->getResourceType());
        self::assertSame('Hello', $operation->body()->getResourceAttribute('title'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aMissingTargetAttributeYieldsAnErrorResponse(): void
    {
        $handler = new RecordingOperationHandler(
            DataResponse::fromResource(new \stdClass(), new StubResource('articles', '1')),
        );
        $adapter = new Psr7ToOperationHandlerAdapter($handler, new StubServer());

        $response = $adapter->handle(new ServerRequest('GET', '/articles'));

        self::assertNull($handler->received);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }
}
