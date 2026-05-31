<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Request\JsonApiRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
#[Group('spec:content-negotiation')]
final class ExtensionTest extends TestCase
{
    #[Test]
    public function parsesAppliedExtensionsFromContentTypeHeader(): void
    {
        $request = $this->requestWithHeaders([
            'Content-Type' => 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"',
        ]);

        self::assertSame(['https://jsonapi.org/ext/atomic'], $request->getAppliedExtensions());
    }

    #[Test]
    public function parsesRequestedExtensionsFromAcceptHeader(): void
    {
        $request = $this->requestWithHeaders([
            'Accept' => 'application/vnd.api+json; ext="https://example.com/ext/a https://example.com/ext/b"',
        ]);

        self::assertSame(
            ['https://example.com/ext/a', 'https://example.com/ext/b'],
            $request->getRequestedExtensions(),
        );
    }

    #[Test]
    public function parsesExtensionAndProfileTogetherRegardlessOfOrder(): void
    {
        $request = $this->requestWithHeaders([
            'Content-Type' => 'application/vnd.api+json; ext="https://example.com/ext/a"; profile="https://example.com/profiles/p"',
        ]);

        self::assertSame(['https://example.com/ext/a'], $request->getAppliedExtensions());
        self::assertSame(['https://example.com/profiles/p'], $request->getAppliedProfiles());
    }

    #[Test]
    public function parsesProfileEvenWhenExtensionPrecedesIt(): void
    {
        $request = $this->requestWithHeaders([
            'Accept' => 'application/vnd.api+json; ext="https://example.com/ext/a"; profile="https://example.com/profiles/p"',
        ]);

        self::assertSame(['https://example.com/profiles/p'], $request->getRequestedProfiles());
        self::assertSame(['https://example.com/ext/a'], $request->getRequestedExtensions());
    }

    #[Test]
    public function reportsNoExtensionsWhenAbsent(): void
    {
        $request = $this->requestWithHeaders([
            'Content-Type' => 'application/vnd.api+json; profile="https://example.com/profiles/p"',
        ]);

        self::assertSame([], $request->getAppliedExtensions());
        self::assertSame([], $request->getRequestedExtensions());
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestWithHeaders(array $headers): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/', $headers));
    }
}
