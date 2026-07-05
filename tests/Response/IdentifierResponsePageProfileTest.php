<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\OffsetBasedPage;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see IdentifierResponse::withPage()}: attaching the page that
 * windowed the linkage advertises the page's profile (if any) — in
 * `jsonapi.profile` and the `Content-Type` `profile` media-type parameter,
 * exactly as {@see \haddowg\JsonApi\Response\RelatedResponse::fromPage()} does —
 * while the document body stays byte-identical apart from that. Linkage
 * documents are links-only: the pagination links flow through the
 * relationship-pagination seam, and `meta.page` is never emitted (ADR 0124).
 */
#[Group('spec:fetching-relationships')]
#[Group('spec:extensions-and-profiles')]
final class IdentifierResponsePageProfileTest extends TestCase
{
    #[Test]
    public function withoutAPageTheResponseIsUnchanged(): void
    {
        $server = $this->serverWithCursorProfileRegistered();
        $request = $this->relationshipRequest();

        $psr = $this->response()->toPsrResponse($server, $request);
        $body = $this->decode((string) $psr->getBody());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame('', $psr->getHeaderLine('Vary'));

        $jsonapi = $body['jsonapi'] ?? [];
        self::assertArrayNotHasKey('profile', \is_array($jsonapi) ? $jsonapi : []);
        self::assertArrayNotHasKey('meta', $body);
    }

    #[Test]
    public function anOffsetPageCarriesNoProfileAndLeavesTheResponseByteIdentical(): void
    {
        $server = $this->serverWithCursorProfileRegistered();
        $request = $this->relationshipRequest();

        $base = $this->response();
        $withPage = $base->withPage(new OffsetBasedPage([new \stdClass()], totalItems: 3, offset: 0, limit: 1));

        // withPage is a clone-then-assign wither: a distinct instance comes back.
        self::assertNotSame($base, $withPage);

        $basePsr = $base->toPsrResponse($server, $request);
        $withPagePsr = $withPage->toPsrResponse($server, $request);

        // An offset page activates no profile, so nothing changes — the body is
        // byte-identical and the Content-Type stays bare.
        self::assertSame((string) $basePsr->getBody(), (string) $withPagePsr->getBody());
        self::assertSame('application/vnd.api+json', $withPagePsr->getHeaderLine('Content-Type'));
        self::assertSame('', $withPagePsr->getHeaderLine('Vary'));
    }

    #[Test]
    public function aCursorPageAdvertisesTheCursorProfileWithoutTouchingTheBodyOtherwise(): void
    {
        $server = $this->serverWithCursorProfileRegistered();
        $request = $this->relationshipRequest();

        $page = CursorPaginator::make()->fromBoundaries($request, [new \stdClass()], 'cur-a', 'cur-b', hasNext: true, hasPrevious: false);

        $base = $this->response();
        $basePsr = $base->toPsrResponse($server, $request);
        $psr = $base->withPage($page)->toPsrResponse($server, $request);

        $body = $this->decode((string) $psr->getBody());

        // The profile is advertised in jsonapi.profile and echoed on the Content-Type.
        self::assertIsArray($body['jsonapi']);
        self::assertSame([CursorPaginationProfile::URI], $body['jsonapi']['profile']);
        self::assertStringContainsString(
            'profile="' . CursorPaginationProfile::URI . '"',
            $psr->getHeaderLine('Content-Type'),
        );
        self::assertSame('Accept', $psr->getHeaderLine('Vary'));

        // Apart from jsonapi.profile the body is untouched: no meta.page (linkage
        // documents are links-only) and no top-level pagination links from the page
        // (those flow through the relationship-pagination seam).
        $baseBody = $this->decode((string) $basePsr->getBody());
        $bodyWithoutProfile = $body;
        self::assertIsArray($bodyWithoutProfile['jsonapi']);
        unset($bodyWithoutProfile['jsonapi']['profile']);
        self::assertSame($baseBody, $bodyWithoutProfile);
        self::assertArrayNotHasKey('meta', $body);
    }

    #[Test]
    public function aCursorPageProfileIsDroppedWhenTheServerHasNotRegisteredIt(): void
    {
        // A page must not advertise a profile the server has not registered.
        $request = $this->relationshipRequest();
        $page = CursorPaginator::make()->fromBoundaries($request, [new \stdClass()], 'cur-a', 'cur-b', hasNext: true, hasPrevious: false);

        $psr = $this->response()->withPage($page)->toPsrResponse(new StubServer(baseUri: 'https://api.test'), $request);
        $body = $this->decode((string) $psr->getBody());

        $jsonapi = $body['jsonapi'] ?? [];
        self::assertArrayNotHasKey('profile', \is_array($jsonapi) ? $jsonapi : []);
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame('', $psr->getHeaderLine('Vary'));
    }

    private function response(): IdentifierResponse
    {
        $relatedResource = new StubResource(
            type: 'comment',
            id: '5',
            attributes: ['body' => static fn(): string => 'Great!'],
        );

        $parentResource = new StubResource(
            type: 'article',
            id: '1',
            relationships: [
                'comments' => static fn(mixed $object): ToManyRelationship => ToManyRelationship::create()
                    ->setData([new \stdClass()], $relatedResource),
            ],
        );

        return IdentifierResponse::forRelationship(
            parent: new \stdClass(),
            parentResource: $parentResource,
            relationshipName: 'comments',
        );
    }

    private function serverWithCursorProfileRegistered(): StubServer
    {
        return new StubServer(
            baseUri: 'https://api.test',
            profiles: new ProfileRegistry(new CursorPaginationProfile()),
        );
    }

    private function relationshipRequest(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', 'https://api.test/articles/1/relationships/comments?page[size]=1'));
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
