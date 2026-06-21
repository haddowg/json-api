<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class AtomicResultsResponseTest extends TestCase
{
    #[Test]
    public function rendersTheResultsArrayWith200AndTheExtensionContentType(): void
    {
        $response = AtomicResultsResponse::fromResults([
            AtomicResult::fromDocument(['data' => ['type' => 'articles', 'id' => '1']]),
            AtomicResult::empty(),
        ]);

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame(
            'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"',
            $psr->getHeaderLine('Content-Type'),
        );

        $body = $psr->getBody()->getContents();

        // Pin the wire JSON type: an empty result is a result object `{}`, never the
        // JSON array `[]` an associative decode collapses it to.
        self::assertStringContainsString(
            '"atomic:results":[{"data":{"type":"articles","id":"1"}},{}]',
            $body,
        );

        self::assertSame(
            [
                'atomic:results' => [
                    ['data' => ['type' => 'articles', 'id' => '1']],
                    [],
                ],
                'jsonapi' => ['version' => '1.1'],
            ],
            $this->decode($body),
        );
    }

    #[Test]
    public function rendersAnEmptyResultsArrayForAnEmptyBatch(): void
    {
        $psr = AtomicResultsResponse::fromResults([])
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(
            ['atomic:results' => [], 'jsonapi' => ['version' => '1.1']],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function ordersResultsFirstAndJsonapiLast(): void
    {
        $response = AtomicResultsResponse::fromResults([AtomicResult::empty()])
            ->withMeta(['took' => 5])
            ->withLinks(new DocumentLinks(self: new Link('/operations')));

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(
            ['atomic:results', 'links', 'meta', 'jsonapi'],
            \array_keys($this->decode($psr->getBody()->getContents())),
        );
    }

    #[Test]
    public function carriesDocumentLevelMetaAndLinks(): void
    {
        $response = AtomicResultsResponse::fromResults([AtomicResult::empty()])
            ->withMeta(['took' => 5])
            ->withLinks(new DocumentLinks(self: new Link('/operations')));

        $body = $this->decode(
            $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create())->getBody()->getContents(),
        );

        self::assertSame(['took' => 5], $body['meta']);
        self::assertSame(['self' => '/operations'], $body['links']);
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
