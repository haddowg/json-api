<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class SeeOtherResponseTest extends TestCase
{
    #[Test]
    public function rendersAnEmptyBodyWith303AndTheLocationHeader(): void
    {
        $psr = SeeOtherResponse::to('/articles/42')
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(303, $psr->getStatusCode());
        self::assertSame('', $psr->getBody()->getContents());
        self::assertFalse($psr->hasHeader('Content-Type'));
        self::assertSame('/articles/42', $psr->getHeaderLine('Location'));
    }

    #[Test]
    public function appliesConfiguredHeaders(): void
    {
        $psr = SeeOtherResponse::to('/articles/42')
            ->withHeader('X-Test', 'yes')
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(303, $psr->getStatusCode());
        self::assertSame('yes', $psr->getHeaderLine('X-Test'));
    }
}
