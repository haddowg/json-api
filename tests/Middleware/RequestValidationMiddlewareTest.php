<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Middleware\RequestValidationMiddleware;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use haddowg\JsonApi\Tests\Double\StubSchemaContributingProfile;
use haddowg\JsonApi\Tests\Double\StubServer;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Group('spec:document-structure')]
final class RequestValidationMiddlewareTest extends TestCase
{
    private function middleware(?ProfileRegistry $profiles = null): RequestValidationMiddleware
    {
        return new RequestValidationMiddleware(
            new StubServer(profiles: $profiles),
            new DocumentValidator(new VendoredSchemaProvider()),
        );
    }

    private function okHandler(?ServerRequestInterface &$captured = null): CallableHandler
    {
        return new CallableHandler(function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;

            return (new Psr17Factory())->createResponse(200);
        });
    }

    #[Test]
    public function wellFormedRequestBodyPassesAndReachesHandler(): void
    {
        $captured = null;
        $request = (new ServerRequest('POST', '/articles', ['content-type' => 'application/vnd.api+json']))
            ->withBody(Stream::create('{"data":{"type":"articles","attributes":{"title":"x"}}}'));

        $this->middleware()->process($request, $this->okHandler($captured));

        self::assertInstanceOf(JsonApiRequestInterface::class, $captured);
    }

    #[Test]
    public function malformedDocumentIsRejected(): void
    {
        $request = (new ServerRequest('POST', '/articles', ['content-type' => 'application/vnd.api+json']))
            ->withBody(Stream::create('{"data":{"attributes":{"title":"x"}}}'));

        $this->expectException(RequestBodyInvalidJsonApi::class);

        $this->middleware()->process($request, $this->okHandler());
    }

    #[Test]
    public function bodylessRequestPassesThrough(): void
    {
        $captured = null;
        $request = new ServerRequest('GET', '/articles');

        $response = $this->middleware()->process($request, $this->okHandler($captured));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(JsonApiRequestInterface::class, $captured);
    }

    #[Test]
    public function inScopeProfileFragmentRelaxesValidation(): void
    {
        $uri = 'https://example.com/profiles/aggregations';
        $fragment = \json_decode('{"properties":{"aggregations":{"type":"object"}}}', false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($fragment);

        $registry = new ProfileRegistry(new StubSchemaContributingProfile($uri, $fragment));

        // The body carries a member the base schema rejects; the requested profile
        // contributes a fragment that permits it.
        $request = (new ServerRequest('POST', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json; profile="' . $uri . '"',
        ]))->withBody(Stream::create('{"data":{"type":"articles"},"aggregations":{"count":1}}'));

        $response = $this->middleware($registry)->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function outOfScopeProfileDoesNotRelaxValidation(): void
    {
        $uri = 'https://example.com/profiles/aggregations';
        $fragment = \json_decode('{"properties":{"aggregations":{"type":"object"}}}', false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($fragment);

        // Registered but NOT requested by the request → fragment not applied.
        $registry = new ProfileRegistry(new StubSchemaContributingProfile($uri, $fragment));

        $request = (new ServerRequest('POST', '/articles', ['content-type' => 'application/vnd.api+json']))
            ->withBody(Stream::create('{"data":{"type":"articles"},"aggregations":{"count":1}}'));

        $this->expectException(RequestBodyInvalidJsonApi::class);

        $this->middleware($registry)->process($request, $this->okHandler());
    }
}
