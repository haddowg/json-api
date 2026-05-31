<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgrammaticDispatchTest extends TestCase
{
    #[Test]
    public function anOperationBuiltWithoutAPsr7RequestCanBeHandledAndHasNoHttpRequest(): void
    {
        $context = new OperationContext(new StubServer());
        $operation = new FetchResourceOperation(
            new Target('articles', '1'),
            new QueryParameters([], [], [], [], []),
            $context,
        );

        $handler = new RecordingOperationHandler(MetaResponse::fromMeta(['ok' => true]));

        $response = $handler->handle($operation);

        self::assertSame($operation, $handler->received);
        self::assertInstanceOf(MetaResponse::class, $response);
        self::assertNull($operation->context()->httpRequest());
    }
}
