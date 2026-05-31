<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class RelatedResponseTest extends TestCase
{
    #[Test]
    public function fromResourceRendersSingleRelatedResourceAsData(): void
    {
        $relatedResource = new StubResource('author', '42', attributes: ['name' => static fn(): string => 'Ada']);

        $response = RelatedResponse::fromResource(
            parent: new \stdClass(),
            relationshipName: 'author',
            related: new \stdClass(),
            relatedResource: $relatedResource,
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'jsonapi' => ['version' => '1.1'],
                'data' => [
                    'type' => 'author',
                    'id' => '42',
                    'attributes' => ['name' => 'Ada'],
                ],
            ],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function fromCollectionRendersListOfRelatedResourcesAsData(): void
    {
        // Use objects carrying distinct ids so the compound-document dedup logic
        // does not collapse them into a single entry.
        $item1 = new \stdClass();
        $item1->id = '10';
        $item2 = new \stdClass();
        $item2->id = '20';

        // A resource whose getId delegates to the domain object's id property so
        // the two items produce distinct identifiers.
        $relatedResource = new class extends AbstractSerializer {
            public function getType(mixed $object): string
            {
                return 'comment';
            }

            public function getId(mixed $object): string
            {
                return \is_object($object) && isset($object->id) ? (string) $object->id : '';
            }

            /** @return array<string, mixed> */
            public function getMeta(mixed $object): array
            {
                return [];
            }

            public function getLinks(mixed $object): ?ResourceLinks
            {
                return null;
            }

            /** @return array<string, callable> */
            public function getAttributes(mixed $object): array
            {
                return [];
            }

            /** @return list<string> */
            public function getDefaultIncludedRelationships(mixed $object): array
            {
                return [];
            }

            /** @return array<string, callable> */
            public function getRelationships(mixed $object): array
            {
                return [];
            }
        };

        $response = RelatedResponse::fromCollection(
            parent: new \stdClass(),
            relationshipName: 'comments',
            related: [$item1, $item2],
            relatedResource: $relatedResource,
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'jsonapi' => ['version' => '1.1'],
                'data' => [
                    ['type' => 'comment', 'id' => '10'],
                    ['type' => 'comment', 'id' => '20'],
                ],
            ],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function fromCollectionWithEmptyIterableRendersEmptyArray(): void
    {
        $relatedResource = new StubResource('comment', '1');

        $response = RelatedResponse::fromCollection(
            parent: new \stdClass(),
            relationshipName: 'comments',
            related: [],
            relatedResource: $relatedResource,
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertArrayHasKey('data', $body);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function withMetaReturnsNewInstanceWithMetaInOutput(): void
    {
        $relatedResource = new StubResource('author', '1');

        $base = RelatedResponse::fromResource(
            parent: new \stdClass(),
            relationshipName: 'author',
            related: new \stdClass(),
            relatedResource: $relatedResource,
        );

        $withMeta = $base->withMeta(['custom' => 'value']);

        self::assertNotSame($base, $withMeta);

        $originalBody = $this->decode(
            $base->toPsrResponse(new StubServer(), StubJsonApiRequest::create())->getBody()->getContents(),
        );
        self::assertArrayNotHasKey('meta', $originalBody);

        $withMetaBody = $this->decode(
            $withMeta->toPsrResponse(new StubServer(), StubJsonApiRequest::create())->getBody()->getContents(),
        );
        self::assertSame(['custom' => 'value'], $withMetaBody['meta']);
    }

    #[Test]
    public function relatedDataIsSerializedWithRelatedResourceNotParent(): void
    {
        // The parent is an 'article' resource, but the related resource is 'author'.
        // The response body must use the related resource's type/id, not the parent's.
        $relatedResource = new StubResource('author', '7', attributes: ['name' => static fn(): string => 'Lovelace']);

        $response = RelatedResponse::fromResource(
            parent: new \stdClass(),
            relationshipName: 'author',
            related: new \stdClass(),
            relatedResource: $relatedResource,
        );

        self::assertSame(
            [
                'jsonapi' => ['version' => '1.1'],
                'data' => [
                    'type' => 'author',
                    'id' => '7',
                    'attributes' => ['name' => 'Lovelace'],
                ],
            ],
            $this->decode(
                $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create())->getBody()->getContents(),
            ),
        );
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
