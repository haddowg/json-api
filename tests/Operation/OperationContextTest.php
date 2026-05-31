<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationContextTest extends TestCase
{
    #[Test]
    public function exposesServerAndOriginatingHttpRequest(): void
    {
        $server = new StubServer();
        $request = new ServerRequest('GET', '/articles');

        $context = new OperationContext($server, $request);

        self::assertSame($server, $context->server);
        self::assertSame($request, $context->httpRequest());
    }

    #[Test]
    public function httpRequestIsNullWhenDispatchedProgrammatically(): void
    {
        $context = new OperationContext(new StubServer());

        self::assertNull($context->httpRequest());
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(OperationContext::class))->isReadOnly());
    }
}
