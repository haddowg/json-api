<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawResponse::class)]
final class RawResponseTest extends TestCase
{
    #[Test]
    public function aStringBodyRendersAsItIs(): void
    {
        $response = (new RawResponse("id,title\n1,Hello\n", 'text/csv; charset=utf-8', headers: ['Content-Disposition' => 'attachment; filename="articles.csv"']))
            ->toPsrResponse(new StubServer(), new ServerRequest('GET', '/articles/-actions/report'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('Accept', $response->getHeaderLine('Vary'));
        self::assertSame('attachment; filename="articles.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame("id,title\n1,Hello\n", (string) $response->getBody());
    }

    #[Test]
    public function aStreamResourceBodyIsStreamed(): void
    {
        $resource = \fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        \fwrite($resource, "\x89PNG");
        \rewind($resource);

        $response = (new RawResponse($resource, 'image/png', 201))
            ->toPsrResponse(new StubServer(), new ServerRequest('GET', '/'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame("\x89PNG", (string) $response->getBody());
    }

    #[Test]
    public function aPsrStreamBodyIsUsedDirectly(): void
    {
        $stream = Stream::create('%PDF-1.7');

        $response = (new RawResponse($stream, 'application/pdf'))->toPsrResponse(new StubServer(), new ServerRequest('GET', '/'));

        self::assertSame($stream, $response->getBody());
    }

    #[Test]
    public function withersReturnACopyAndConfiguredHeadersWin(): void
    {
        $original = new RawResponse('x', 'text/plain');
        $changed = $original->withStatus(202)->withHeader('Vary', 'Accept, Accept-Language');

        self::assertSame(200, $original->status());
        self::assertSame([], $original->headers());
        self::assertSame(202, $changed->status());

        $psr = $changed->toPsrResponse(new StubServer(), new ServerRequest('GET', '/'));
        self::assertSame('Accept, Accept-Language', $psr->getHeaderLine('Vary'));
        self::assertSame('text/plain', $changed->contentType());
        self::assertSame('x', $changed->body());
    }

    #[Test]
    public function itRefusesABodyItCannotSend(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new RawResponse(['not' => 'a body'], 'application/json');
    }

    #[Test]
    public function itRefusesAnEmptyContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RawResponse('x', ' ');
    }

    #[Test]
    public function theCorePsr15PathRendersAHandlersRawResponse(): void
    {
        $handler = new RecordingOperationHandler(new RawResponse('a,b', 'text/csv'));
        $adapter = new Psr7ToOperationHandlerAdapter($handler, new StubServer());

        $response = $adapter->handle((new ServerRequest('GET', '/articles/1'))->withAttribute(Target::class, new Target('articles', '1')));

        self::assertSame('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertSame('a,b', (string) $response->getBody());
    }
}
