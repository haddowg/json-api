<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
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
            related: new \stdClass(),
            relatedResource: $relatedResource,
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'data' => [
                    'type' => 'author',
                    'id' => '42',
                    'links' => ['self' => '/author/42'],
                    'attributes' => ['name' => 'Ada'],
                ],
                'links' => ['self' => '/'],
                'jsonapi' => ['version' => '1.1'],
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
            public function getMeta(mixed $object, JsonApiRequestInterface $request): array
            {
                return [];
            }

            public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
            {
                return null;
            }

            /** @return array<string, callable> */
            public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
            {
                return [];
            }

            /** @return list<string> */
            public function getDefaultIncludedRelationships(mixed $object): array
            {
                return [];
            }

            /** @return array<string, callable> */
            public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
            {
                return [];
            }
        };

        $response = RelatedResponse::fromCollection(
            related: [$item1, $item2],
            relatedResource: $relatedResource,
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'data' => [
                    ['type' => 'comment', 'id' => '10', 'links' => ['self' => '/comment/10']],
                    ['type' => 'comment', 'id' => '20', 'links' => ['self' => '/comment/20']],
                ],
                'links' => ['self' => '/'],
                'jsonapi' => ['version' => '1.1'],
            ],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function fromCollectionWithEmptyIterableRendersEmptyArray(): void
    {
        $relatedResource = new StubResource('comment', '1');

        $response = RelatedResponse::fromCollection(
            related: [],
            relatedResource: $relatedResource,
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertArrayHasKey('data', $body);
        self::assertSame([], $body['data']);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPageEmitsPaginationLinksScopedToTheRelatedCollectionUrlAndMeta(): void
    {
        // The client hit /articles/1/comments — the page links must be scoped to
        // THAT related-collection path (with its query string), not a primary
        // /comments collection, mirroring DataResponse::fromPage's behaviour.
        $relatedResource = new StubResource('comment', '1');
        $page = new PageBasedPage([new \stdClass()], totalItems: 50, page: 2, size: 10);

        $request = new JsonApiRequest(new ServerRequest(
            'GET',
            'https://api.test/articles/1/comments?page[number]=2&page[size]=10',
        ));

        $psr = RelatedResponse::fromPage(
            page: $page,
            relatedSerializer: $relatedResource,
        )->toPsrResponse(new StubServer(baseUri: 'https://api.test'), $request);

        $body = $this->decode($psr->getBody()->getContents());

        $links = $body['links'];
        self::assertIsArray($links);
        self::assertSame('https://api.test/articles/1/comments?page%5Bnumber%5D=2&page%5Bsize%5D=10', $links['self']);
        self::assertSame('https://api.test/articles/1/comments?page%5Bnumber%5D=1&page%5Bsize%5D=10', $links['first']);
        self::assertSame('https://api.test/articles/1/comments?page%5Bnumber%5D=1&page%5Bsize%5D=10', $links['prev']);
        self::assertSame('https://api.test/articles/1/comments?page%5Bnumber%5D=3&page%5Bsize%5D=10', $links['next']);
        self::assertSame('https://api.test/articles/1/comments?page%5Bnumber%5D=5&page%5Bsize%5D=10', $links['last']);

        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertSame(
            ['currentPage' => 2, 'perPage' => 10, 'from' => 11, 'to' => 20, 'total' => 50, 'lastPage' => 5],
            $meta['page'],
        );

        self::assertSame(
            [['type' => 'comment', 'id' => '1', 'links' => ['self' => 'https://api.test/comment/1']]],
            $body['data'],
        );
    }

    #[Test]
    public function withMetaReturnsNewInstanceWithMetaInOutput(): void
    {
        $relatedResource = new StubResource('author', '1');

        $base = RelatedResponse::fromResource(
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
            related: new \stdClass(),
            relatedResource: $relatedResource,
        );

        self::assertSame(
            [
                'data' => [
                    'type' => 'author',
                    'id' => '7',
                    'links' => ['self' => '/author/7'],
                    'attributes' => ['name' => 'Lovelace'],
                ],
                'links' => ['self' => '/'],
                'jsonapi' => ['version' => '1.1'],
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
