<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class NoContentResponseTest extends TestCase
{
    #[Test]
    public function rendersAnEmptyBodyWith204AndNoContentType(): void
    {
        $psr = NoContentResponse::create()
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(204, $psr->getStatusCode());
        self::assertSame('', $psr->getBody()->getContents());
        self::assertFalse($psr->hasHeader('Content-Type'));
    }

    #[Test]
    public function appliesConfiguredHeaders(): void
    {
        $psr = NoContentResponse::create()
            ->withHeader('X-Test', 'yes')
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(204, $psr->getStatusCode());
        self::assertSame('yes', $psr->getHeaderLine('X-Test'));
    }
}
