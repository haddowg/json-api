<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class MetaResponseTest extends TestCase
{
    #[Test]
    public function fromMetaRendersMetaWithoutDataOrErrors(): void
    {
        $psr = MetaResponse::fromMeta(['status' => 'ok'])
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(['status' => 'ok'], $body['meta']);
        self::assertArrayNotHasKey('data', $body);
        self::assertArrayNotHasKey('errors', $body);
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
