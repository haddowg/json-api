<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator;

use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Tests\Hydrator\Double\StubCreateHydrator;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CreateHydratorTrait via StubCreateHydrator.
 *
 * Hydrators throw typed exceptions directly; requests are built with Nyholm PSR-7.
 */
final class CreateHydratorTraitTest extends TestCase
{
    #[Test]
    public function hydrateWhenBodyEmpty(): void
    {
        $body = [];

        $hydrator = $this->createHydrator(false, '1');

        $this->expectException(DataMemberMissing::class);
        $hydrator->hydrateForCreate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateWhenGeneratingId(): void
    {
        $type = 'user';
        $id = '1';
        $body = [
            'data' => [
                'type' => $type,
            ],
        ];

        $hydrator = $this->createHydrator(false, $id);
        $domainObject = $hydrator->hydrateForCreate($this->createRequest($body), []);
        self::assertEquals(['id' => $id], $domainObject);
    }

    #[Test]
    public function hydrateWhenBodyDataIdNotSupported(): void
    {
        $type = 'user';
        $id = '1';
        $body = [
            'data' => [
                'type' => $type,
                'id' => $id,
            ],
        ];

        $hydrator = $this->createHydrator(true, $id);

        $this->expectException(ClientGeneratedIdNotSupported::class);
        $hydrator->hydrateForCreate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateBodyDataId(): void
    {
        $type = 'user';
        $id = '1';
        $body = [
            'data' => [
                'type' => $type,
                'id' => $id,
            ],
        ];

        $hydrator = $this->createHydrator(false, $id);
        $domainObject = $hydrator->hydrateForCreate($this->createRequest($body), []);
        self::assertEquals(['id' => $id], $domainObject);
    }

    #[Test]
    public function hydratesToOneRelationshipReferencedByLid(): void
    {
        $body = [
            'data' => [
                'type' => 'user',
                'id' => '1',
                'relationships' => [
                    'owner' => [
                        'data' => ['type' => 'person', 'lid' => 'local-person-1'],
                    ],
                ],
            ],
        ];

        $hydrator = $this->createHydrator(false, '1');
        $hydrator->hydrateForCreate($this->createRequest($body), []);

        self::assertNotNull($hydrator->capturedOwner);
        self::assertNotNull($hydrator->capturedOwner->resourceIdentifier);
        self::assertSame('local-person-1', $hydrator->capturedOwner->resourceIdentifier->lid);
        self::assertNull($hydrator->capturedOwner->resourceIdentifier->id);
        self::assertSame('person', $hydrator->capturedOwner->resourceIdentifier->type);
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

        $hydrator = $this->createHydrator(false, $id, true);

        $this->expectException(\LogicException::class);
        $hydrator->hydrateForCreate($this->createRequest($body), []);
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

        $psrRequest = (new ServerRequest('POST', '/'))
            ->withParsedBody($body)
            ->withBody($stream);

        return new JsonApiRequest($psrRequest);
    }

    private function createHydrator(
        bool $clientGeneratedIdException = false,
        string $generatedId = '',
        bool $logicException = false,
    ): StubCreateHydrator {
        return new StubCreateHydrator($clientGeneratedIdException, $generatedId, $logicException);
    }
}
