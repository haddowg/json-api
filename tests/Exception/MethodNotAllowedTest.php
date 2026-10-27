<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception;

use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\MethodNotAllowed;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodNotAllowed::class)]
#[Group('spec:errors')]
final class MethodNotAllowedTest extends TestCase
{
    #[Test]
    public function itIsA405NamingTheMethodsThePathAccepts(): void
    {
        $exception = new MethodNotAllowed('post', ['GET', 'PATCH']);

        self::assertSame(405, $exception->getStatusCode());

        $error = $exception->getErrors()[0];
        self::assertSame('405', $error->status);
        self::assertSame('METHOD_NOT_ALLOWED', $error->code);
        self::assertSame('Method Not Allowed', $error->title);
        self::assertSame('The method POST is not allowed on this path, which accepts GET, PATCH.', $error->detail);
    }

    #[Test]
    public function theErrorResponseCarriesTheAllowHeader(): void
    {
        $psr17 = new Psr17Factory();
        $response = ErrorResponse::fromException(new MethodNotAllowed('POST', ['get', 'GET', 'head']))
            ->toPsrResponse(Server::make()->withPsr17($psr17, $psr17), new ServerRequest('POST', '/agencies/1'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aPathAcceptingNoMethodStillSendsAnEmptyAllow(): void
    {
        $exception = new MethodNotAllowed('DELETE', []);

        self::assertSame(['Allow' => ''], $exception->responseHeaders());
        self::assertSame('The method DELETE is not allowed on this path.', $exception->getErrors()[0]->detail);
    }

    #[Test]
    public function itKeepsTheFrameworkExceptionItTranslates(): void
    {
        $previous = new \RuntimeException('No route found');

        self::assertSame($previous, (new MethodNotAllowed('PATCH', ['GET'], $previous))->getPrevious());
    }

    #[Test]
    public function itIsGatedOnTheAdapterRenderingIt(): void
    {
        self::assertSame(ErrorFeature::MethodNotAllowed, MethodNotAllowed::describe()->feature);
    }
}
