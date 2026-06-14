<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The spec-recommended top-level `links.self` — the URI that produced the
 * document — is emitted by convention on every data/resource document (single,
 * collection, related, relationship, meta), but not on error documents.
 */
#[Group('spec:document-top-level')]
final class TopLevelSelfLinkTest extends TestCase
{
    #[Test]
    public function aSingleResourceDocumentCarriesTheRequestUriAsTopLevelSelf(): void
    {
        $self = $this->selfOf(
            DataResponse::fromResource(new \stdClass(), new StubResource('user', '1')),
            'https://api.test/users/1',
        );

        self::assertSame('https://api.test/users/1', $self);
    }

    #[Test]
    public function aCollectionDocumentCarriesTheRequestUriAsTopLevelSelf(): void
    {
        $self = $this->selfOf(
            DataResponse::fromCollection([new \stdClass()], new StubResource('user', '1')),
            'https://api.test/users',
        );

        self::assertSame('https://api.test/users', $self);
    }

    #[Test]
    public function theTopLevelSelfIncludesTheQueryStringWhenPresent(): void
    {
        $self = $this->selfOf(
            DataResponse::fromCollection([new \stdClass()], new StubResource('user', '1')),
            'https://api.test/users?filter[name]=ada&sort=name',
        );

        self::assertSame('https://api.test/users?filter%5Bname%5D=ada&sort=name', $self);
    }

    #[Test]
    public function aRelatedDocumentCarriesTheRelatedUrlAsTopLevelSelf(): void
    {
        $self = $this->selfOf(
            RelatedResponse::fromResource(new \stdClass(), new StubResource('author', '7')),
            'https://api.test/articles/1/author',
        );

        self::assertSame('https://api.test/articles/1/author', $self);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipDocumentCarriesTheRelationshipUrlAsTopLevelSelf(): void
    {
        $related = new StubResource('author', '7');
        $parent = new StubResource('article', '1', relationships: [
            'author' => static fn(): ToOneRelationship => ToOneRelationship::create()
                ->setData(new \stdClass(), $related),
        ]);

        $self = $this->selfOf(
            IdentifierResponse::forRelationship(new \stdClass(), $parent, 'author'),
            'https://api.test/articles/1/relationships/author',
        );

        self::assertSame('https://api.test/articles/1/relationships/author', $self);
    }

    #[Test]
    public function aMetaDocumentCarriesTheRequestUriAsTopLevelSelf(): void
    {
        $self = $this->selfOf(
            MetaResponse::fromMeta(['queued' => true]),
            'https://api.test/jobs',
        );

        self::assertSame('https://api.test/jobs', $self);
    }

    #[Test]
    public function aHandSetTopLevelSelfWinsOverTheConvention(): void
    {
        $response = DataResponse::fromResource(new \stdClass(), new StubResource('user', '1'))
            ->withLinks(DocumentLinks::withBaseUri('https://api.test', self: new Link('/canonical')));

        $self = $this->selfOf($response, 'https://api.test/users/1');

        self::assertSame('https://api.test/canonical', $self);
    }

    /**
     * Renders the response against the given request URI and returns the
     * document's top-level `links.self`.
     */
    private function selfOf(
        DataResponse|RelatedResponse|IdentifierResponse|MetaResponse $response,
        string $uri,
    ): string {
        $request = new JsonApiRequest(new ServerRequest('GET', $uri));

        $psr = $response->toPsrResponse(new StubServer(baseUri: 'https://api.test'), $request);

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode((string) $psr->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        $links = $decoded['links'] ?? [];
        self::assertIsArray($links);
        $self = $links['self'] ?? null;
        self::assertIsString($self);

        return $self;
    }
}
