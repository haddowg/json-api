<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class DataResponsePaginationTest extends TestCase
{
    #[Test]
    public function pageBasedResponseEmitsPaginationLinksAndMeta(): void
    {
        $resource = new StubResource('user', '1');
        $page = new PageBasedPage([new \stdClass()], totalItems: 50, page: 2, size: 10);

        $body = $this->render(DataResponse::fromPage($page, $resource), 'https://api.test/users?page[number]=2&page[size]=10');

        $links = $body['links'];
        self::assertIsArray($links);
        self::assertSame('https://api.test/users?page%5Bnumber%5D=2&page%5Bsize%5D=10', $links['self']);
        self::assertSame('https://api.test/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $links['first']);
        self::assertSame('https://api.test/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $links['prev']);
        self::assertSame('https://api.test/users?page%5Bnumber%5D=3&page%5Bsize%5D=10', $links['next']);
        self::assertSame('https://api.test/users?page%5Bnumber%5D=5&page%5Bsize%5D=10', $links['last']);

        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertSame(
            ['currentPage' => 2, 'perPage' => 10, 'from' => 11, 'to' => 20, 'total' => 50, 'lastPage' => 5],
            $meta['page'],
        );

        self::assertSame(
            [['type' => 'user', 'id' => '1', 'links' => ['self' => 'https://api.test/user/1']]],
            $body['data'],
        );
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function cursorResponseOmitsLastAndAdvertisesTheCursorProfileWhenRegistered(): void
    {
        $resource = new StubResource('user', '1');
        $request = new JsonApiRequest(new ServerRequest('GET', 'https://api.test/users?page[size]=10'));
        $server = new StubServer(
            baseUri: 'https://api.test',
            profiles: new ProfileRegistry(new CursorPaginationProfile()),
        );

        $page = CursorPaginator::make()->paginate($request, [new \stdClass()], 'cur-a', 'cur-b', hasNext: true, hasPrevious: false);

        $psr = DataResponse::fromPage($page, $resource)->toPsrResponse($server, $request);
        $body = $this->decode((string) $psr->getBody());

        $links = $body['links'];
        self::assertIsArray($links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('last', $links, 'cursor pagination must not emit a last link');
        self::assertSame(
            ['https://jsonapi.org/profiles/ethanresnick/cursor-pagination/'],
            $links['profile'],
        );

        self::assertStringContainsString(
            'profile="https://jsonapi.org/profiles/ethanresnick/cursor-pagination/"',
            $psr->getHeaderLine('Content-Type'),
        );
        self::assertSame('Accept', $psr->getHeaderLine('Vary'));
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function cursorResponseDoesNotAdvertiseTheProfileWhenServerHasNotRegisteredIt(): void
    {
        // A page must not advertise a profile the server has not registered.
        $resource = new StubResource('user', '1');
        $request = new JsonApiRequest(new ServerRequest('GET', 'https://api.test/users?page[size]=10'));

        $page = CursorPaginator::make()->paginate($request, [new \stdClass()], 'cur-a', 'cur-b', hasNext: true, hasPrevious: false);

        // Empty registry on the server: the page's profile is unrecognized → dropped.
        $psr = DataResponse::fromPage($page, $resource)->toPsrResponse(new StubServer(baseUri: 'https://api.test'), $request);
        $body = $this->decode((string) $psr->getBody());

        $links = $body['links'];
        self::assertIsArray($links);
        self::assertArrayHasKey('next', $links, 'pagination links are still emitted');
        self::assertArrayNotHasKey('profile', $links);

        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame('', $psr->getHeaderLine('Vary'));
    }

    /**
     * @return array<string, mixed>
     */
    private function render(DataResponse $response, string $uri): array
    {
        $request = new JsonApiRequest(new ServerRequest('GET', $uri));

        return $this->decode((string) $response->toPsrResponse(new StubServer(baseUri: 'https://api.test'), $request)->getBody());
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
