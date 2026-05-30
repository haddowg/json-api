<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator;

use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\ResourceIdMissing;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Tests\Hydrator\Double\StubUpdateHydrator;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UpdateHydratorTrait via StubUpdateHydrator.
 *
 * Rewrites from yin:
 * - ExceptionFactory removed from all call sites.
 * - Request built with Nyholm PSR-7 (replaces Laminas/Diactoros).
 * - PHPUnit 12 attributes only.
 */
final class UpdateHydratorTraitTest extends TestCase
{
    #[Test]
    public function hydrateWhenBodyEmpty(): void
    {
        $body = [];

        $hydrator = $this->createHydrator();

        $this->expectException(DataMemberMissing::class);
        $hydrator->hydrateForUpdate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateWhenIdMissing(): void
    {
        $body = [
            'data' => [
                'type' => 'user',
            ],
        ];

        $hydrator = $this->createHydrator();

        $this->expectException(ResourceIdMissing::class);
        $hydrator->hydrateForUpdate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateId(): void
    {
        $id = '1';
        $body = [
            'data' => [
                'type' => 'user',
                'id' => $id,
            ],
        ];

        $hydrator = $this->createHydrator();
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals(['id' => $id], $domainObject);
    }

    #[Test]
    public function validateRequest(): void
    {
        $type = 'user';
        $id = '1';

        $body = [
            'data' => [
                'type' => $type,
                'id' => $id,
            ],
        ];

        $hydrator = $this->createHydrator(true);

        $this->expectException(\LogicException::class);
        $hydrator->hydrateForUpdate($this->createRequest($body), []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRequest(array $body): JsonApiRequest
    {
        $json = \json_encode($body);
        if ($json === false) {
            $json = '';
        }

        $stream = Stream::create($json);

        $psrRequest = (new ServerRequest('PATCH', '/'))
            ->withParsedBody($body)
            ->withBody($stream);

        return new JsonApiRequest($psrRequest);
    }

    private function createHydrator(bool $validationException = false): StubUpdateHydrator
    {
        return new StubUpdateHydrator($validationException);
    }
}
