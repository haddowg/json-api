<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class ErrorResponseTest extends TestCase
{
    #[Test]
    public function fromErrorsRendersTopLevelErrorsWithSingleStatus(): void
    {
        $error = new Error(status: '404', code: 'NOT_FOUND', title: 'Resource not found');

        $psr = ErrorResponse::fromErrors($error)
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertSame(404, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertArrayNotHasKey('data', $body);
        self::assertSame(
            [['status' => '404', 'code' => 'NOT_FOUND', 'title' => 'Resource not found']],
            $body['errors'],
        );
    }

    #[Test]
    public function fromExceptionDerivesErrorsAndStatusFromException(): void
    {
        $psr = ErrorResponse::fromException(new ResourceNotFound())
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertSame(404, $psr->getStatusCode());
        self::assertSame(
            [['status' => '404', 'code' => 'RESOURCE_NOT_FOUND', 'title' => 'Resource not found', 'detail' => 'The requested resource is not found!']],
            $body['errors'],
        );
    }

    #[Test]
    public function mixedStatusesRoundToServerErrorClass(): void
    {
        $psr = ErrorResponse::fromErrors(
            new Error(status: '404'),
            new Error(status: '500'),
        )->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        // Two errors spanning 4xx and 5xx round to 500 per AbstractErrorDocument.
        self::assertSame(500, $psr->getStatusCode());
    }

    #[Test]
    public function metaAndJsonApiAppearInErrorOutput(): void
    {
        $psr = ErrorResponse::fromErrors(new Error(status: '422'))
            ->withMeta(['trace' => 'abc'])
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertSame(422, $psr->getStatusCode());
        self::assertSame(['trace' => 'abc'], $body['meta']);
        self::assertSame(['version' => '1.1'], $body['jsonapi']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
