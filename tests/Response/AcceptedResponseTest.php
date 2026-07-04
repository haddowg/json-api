<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class AcceptedResponseTest extends TestCase
{
    #[Test]
    public function forResourceRendersTheJobResourceWith202(): void
    {
        $resource = new StubResource('queue-jobs', '5234', attributes: ['status' => static fn(): string => 'processing']);

        $psr = AcceptedResponse::forResource(new \stdClass(), $resource)
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(202, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'data' => [
                    'type' => 'queue-jobs',
                    'id' => '5234',
                    'links' => ['self' => '/queue-jobs/5234'],
                    'attributes' => ['status' => 'processing'],
                ],
                'jsonapi' => ['version' => '1.1'],
            ],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function fromMetaRendersAMetaOnlyStatusDocumentWith202(): void
    {
        $psr = AcceptedResponse::fromMeta(['status' => 'queued'])
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(202, $psr->getStatusCode());
        self::assertSame(
            [
                'meta' => ['status' => 'queued'],
                'jsonapi' => ['version' => '1.1'],
            ],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function withContentLocationAdvertisesThePollingUrl(): void
    {
        $psr = AcceptedResponse::fromMeta(['status' => 'queued'])
            ->withContentLocation('/queue-jobs/5234')
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame('/queue-jobs/5234', $psr->getHeaderLine('Content-Location'));
    }

    #[Test]
    public function withRetryAfterIntEmitsDeltaSeconds(): void
    {
        $psr = AcceptedResponse::fromMeta([])
            ->withRetryAfter(30)
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame('30', $psr->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function withRetryAfterDateEmitsAnImfFixdateInGmt(): void
    {
        $when = new \DateTimeImmutable('2026-07-04T12:00:00+02:00');

        $psr = AcceptedResponse::fromMeta([])
            ->withRetryAfter($when)
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        // 12:00 +02:00 is 10:00 GMT.
        self::assertSame('Sat, 04 Jul 2026 10:00:00 GMT', $psr->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function withRetryAfterDoesNotMutateAPassedMutableDateTime(): void
    {
        $when = new \DateTime('2026-07-04T12:00:00+02:00');

        AcceptedResponse::fromMeta([])
            ->withRetryAfter($when)
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame('+02:00', $when->format('P'));
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
