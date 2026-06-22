<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Link\ErrorLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * With no base URI configured, every generated link is request-absolute — built
 * from the origin (`<scheme>://<authority>`) of the request that produced the
 * document. A configured base URI pins a fixed canonical host instead (and is
 * trailing-slash tolerant). Asserted here per link type, end-to-end through the
 * response render.
 *
 * @see \haddowg\JsonApi\Server\RequestBaseUri
 */
#[Group('spec:document-links')]
final class RequestAbsoluteLinksTest extends TestCase
{
    private const ORIGIN = 'https://music.example';

    // --- empty base_uri => request-absolute, for every link type -------------

    #[Test]
    public function theResourceSelfLinkIsRequestAbsolute(): void
    {
        $body = $this->renderWithEmptyBase(
            DataResponse::fromResource(new \stdClass(), new StubResource('album', '1')),
            self::ORIGIN . '/albums/1',
        );

        self::assertSame(self::ORIGIN . '/album/1', $this->linkAt($body, 'data', 'links', 'self'));
    }

    #[Test]
    public function theDocumentSelfLinkIsRequestAbsolute(): void
    {
        $body = $this->renderWithEmptyBase(
            DataResponse::fromCollection([new \stdClass()], new StubResource('album', '1')),
            self::ORIGIN . '/albums?sort=title',
        );

        self::assertSame(self::ORIGIN . '/albums?sort=title', $this->linkAt($body, 'links', 'self'));
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function theRelationshipSelfAndRelatedLinksAreRequestAbsolute(): void
    {
        $album = ['author' => ['id' => '7', 'type' => 'people']];
        $relation = BelongsTo::make('author', 'people');
        $resolver = new StubSerializerResolver();

        $resource = new StubResource('album', '1', relationships: [
            'author' => static fn(mixed $model, $request): \haddowg\JsonApi\Schema\Relationship\AbstractRelationship => $relation->buildRelationship($model, $request, $resolver),
        ]);

        $body = $this->renderWithEmptyBase(
            DataResponse::fromResource($album, $resource),
            self::ORIGIN . '/albums/1',
        );

        self::assertSame(self::ORIGIN . '/album/1/relationships/author', $this->linkAt($body, 'data', 'relationships', 'author', 'links', 'self'));
        self::assertSame(self::ORIGIN . '/album/1/author', $this->linkAt($body, 'data', 'relationships', 'author', 'links', 'related'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipDocumentLinksAreRequestAbsolute(): void
    {
        $album = ['tracks' => [['id' => '10', 'type' => 'track'], ['id' => '20', 'type' => 'track']]];
        $relation = HasMany::make('tracks', 'track');
        $resolver = new StubSerializerResolver();

        $resource = new StubResource('album', '1', relationships: [
            'tracks' => static fn(mixed $model, $request): \haddowg\JsonApi\Schema\Relationship\AbstractRelationship => $relation->buildRelationship($model, $request, $resolver),
        ]);

        $body = $this->renderWithEmptyBase(
            IdentifierResponse::forRelationship($album, $resource, 'tracks'),
            self::ORIGIN . '/albums/1/relationships/tracks',
        );

        self::assertSame(self::ORIGIN . '/album/1/relationships/tracks', $this->linkAt($body, 'links', 'self'));
        self::assertSame(self::ORIGIN . '/album/1/tracks', $this->linkAt($body, 'links', 'related'));
    }

    #[Test]
    public function theRelatedDocumentSelfLinkIsRequestAbsolute(): void
    {
        $body = $this->renderWithEmptyBase(
            RelatedResponse::fromResource(new \stdClass(), new StubResource('person', '7')),
            self::ORIGIN . '/albums/1/author',
        );

        self::assertSame(self::ORIGIN . '/albums/1/author', $this->linkAt($body, 'links', 'self'));
        self::assertSame(self::ORIGIN . '/person/7', $this->linkAt($body, 'data', 'links', 'self'));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function thePaginationLinksAreRequestAbsolute(): void
    {
        $page = new PageBasedPage([new \stdClass()], totalItems: 50, page: 2, size: 10);

        $body = $this->renderWithEmptyBase(
            DataResponse::fromPage($page, new StubResource('album', '1')),
            self::ORIGIN . '/albums?page[number]=2&page[size]=10',
        );

        self::assertSame(self::ORIGIN . '/albums?page%5Bnumber%5D=2&page%5Bsize%5D=10', $this->linkAt($body, 'links', 'self'));
        self::assertSame(self::ORIGIN . '/albums?page%5Bnumber%5D=1&page%5Bsize%5D=10', $this->linkAt($body, 'links', 'first'));
        self::assertSame(self::ORIGIN . '/albums?page%5Bnumber%5D=1&page%5Bsize%5D=10', $this->linkAt($body, 'links', 'prev'));
        self::assertSame(self::ORIGIN . '/albums?page%5Bnumber%5D=3&page%5Bsize%5D=10', $this->linkAt($body, 'links', 'next'));
        self::assertSame(self::ORIGIN . '/albums?page%5Bnumber%5D=5&page%5Bsize%5D=10', $this->linkAt($body, 'links', 'last'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function theErrorAboutAndTypeLinksAreRequestAbsoluteWhenRelative(): void
    {
        // Author-supplied error links with RELATIVE hrefs are rebased to the request
        // origin, exactly like the by-convention links.
        $response = ErrorResponse::fromErrors(
            new Error(
                status: '404',
                title: 'Not found',
                links: ErrorLinks::withoutBaseUri(
                    new Link('/errors/404'),
                    [new Link('/errors/types/not-found')],
                ),
            ),
        );

        $body = $this->renderWithEmptyBase($response, self::ORIGIN . '/albums/999');

        $links = $this->errorLinks($body);
        self::assertSame(self::ORIGIN . '/errors/404', $links['about']);
        self::assertSame([self::ORIGIN . '/errors/types/not-found'], $links['type']);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anAbsoluteErrorLinkIsNeverRebasedNorCorrupted(): void
    {
        // An error `about`/`type` link is commonly an absolute documentation URL —
        // it must pass through untouched, never prefixed by the request origin.
        $response = ErrorResponse::fromErrors(
            new Error(
                status: '404',
                links: ErrorLinks::withoutBaseUri(
                    new Link('https://docs.example/errors/404'),
                    [new Link('https://docs.example/errors/types/not-found')],
                ),
            ),
        );

        $body = $this->renderWithEmptyBase($response, self::ORIGIN . '/albums/999');

        $links = $this->errorLinks($body);
        self::assertSame('https://docs.example/errors/404', $links['about']);
        self::assertSame(['https://docs.example/errors/types/not-found'], $links['type']);
    }

    // --- configured base_uri => fixed canonical host, host of request ignored -

    #[Test]
    public function aConfiguredBasePinsTheCanonicalHostRegardlessOfTheRequestHost(): void
    {
        $body = $this->render(
            DataResponse::fromResource(new \stdClass(), new StubResource('album', '1')),
            'https://canonical.example',
            self::ORIGIN . '/albums/1',
        );

        self::assertSame('https://canonical.example/album/1', $this->linkAt($body, 'data', 'links', 'self'));
        self::assertSame('https://canonical.example/albums/1', $this->linkAt($body, 'links', 'self'));
    }

    #[Test]
    public function aConfiguredBaseWithATrailingSlashDoesNotDoubleSlash(): void
    {
        $body = $this->render(
            DataResponse::fromResource(new \stdClass(), new StubResource('album', '1')),
            'https://canonical.example/',
            self::ORIGIN . '/albums/1',
        );

        self::assertSame('https://canonical.example/album/1', $this->linkAt($body, 'data', 'links', 'self'));
        self::assertSame('https://canonical.example/albums/1', $this->linkAt($body, 'links', 'self'));
    }

    #[Test]
    public function aConfiguredPathBaseWithATrailingSlashDoesNotDoubleSlash(): void
    {
        // The by-convention resource self is `{base}/{type}/{id}`; a path base of
        // `/api/` must contribute exactly `/api`, never `/api/` (no double slash).
        $body = $this->render(
            DataResponse::fromResource(new \stdClass(), new StubResource('album', '1')),
            '/api/',
            self::ORIGIN . '/albums/1',
        );

        self::assertSame('/api/album/1', $this->linkAt($body, 'data', 'links', 'self'));
        // The document self is `{base}{request.path}` = `/api` + `/albums/1`.
        self::assertSame('/api/albums/1', $this->linkAt($body, 'links', 'self'));
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Walks `$path` through the decoded body, asserting an array at each hop, and
     * returns the string at the leaf.
     *
     * @param array<string, mixed> $body
     */
    private function linkAt(array $body, string ...$path): string
    {
        $cursor = $body;
        $leaf = \array_key_last($path);

        foreach ($path as $i => $key) {
            self::assertArrayHasKey($key, $cursor);
            $value = $cursor[$key];

            if ($i === $leaf) {
                self::assertIsString($value);

                return $value;
            }

            self::assertIsArray($value);
            $cursor = $value;
        }

        self::fail('empty link path');
    }

    /**
     * The decoded `links` object of the first error in the body.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function errorLinks(array $body): array
    {
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey(0, $body['errors']);
        self::assertIsArray($body['errors'][0]);
        self::assertArrayHasKey('links', $body['errors'][0]);
        self::assertIsArray($body['errors'][0]['links']);

        return $body['errors'][0]['links'];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderWithEmptyBase(
        DataResponse|RelatedResponse|IdentifierResponse|ErrorResponse $response,
        string $uri,
    ): array {
        return $this->render($response, '', $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(
        DataResponse|RelatedResponse|IdentifierResponse|ErrorResponse $response,
        string $configuredBase,
        string $uri,
    ): array {
        $request = new JsonApiRequest(new ServerRequest('GET', $uri));
        $psr = $response->toPsrResponse(new StubServer(baseUri: $configuredBase), $request);

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode((string) $psr->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
