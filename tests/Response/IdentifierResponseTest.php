<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see IdentifierResponse}: relationship-endpoint responses that emit
 * resource-identifier linkage only (no attributes/relationships on related resources).
 *
 * Uses `StubResource` with a configured `relationships` callable that returns a
 * to-one or to-many relationship with a related resource — only `type` + `id`
 * must appear in the output `data`, never `attributes`.
 */
#[Group('spec:document-structure')]
#[Group('spec:fetching-relationships')]
final class IdentifierResponseTest extends TestCase
{
    #[Test]
    public function forRelationshipEmitsToOneIdentifierWithNoAttributes(): void
    {
        $relatedResource = new StubResource(
            type: 'author',
            id: '42',
            attributes: ['name' => static fn(): string => 'Ada'],
        );

        $parentResource = new StubResource(
            type: 'article',
            id: '1',
            relationships: [
                'author' => static fn(mixed $object): ToOneRelationship => ToOneRelationship::create()
                    ->setData(new \stdClass(), $relatedResource),
            ],
        );

        $response = IdentifierResponse::forRelationship(
            parent: new \stdClass(),
            parentResource: $parentResource,
            relationshipName: 'author',
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));

        $body = $this->decode($psr->getBody()->getContents());

        // The data must be a resource identifier — type + id only, no attributes.
        self::assertSame(['type' => 'author', 'id' => '42'], $body['data']);
        self::assertArrayNotHasKey('attributes', $body['data']);
    }

    #[Test]
    public function forRelationshipEmitsToManyIdentifiersWithNoAttributes(): void
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
                    ->setData([new \stdClass(), new \stdClass()], $relatedResource),
            ],
        );

        $response = IdentifierResponse::forRelationship(
            parent: new \stdClass(),
            parentResource: $parentResource,
            relationshipName: 'comments',
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));

        $body = $this->decode($psr->getBody()->getContents());

        // data is a list of resource identifiers with no attributes.
        self::assertSame(
            [
                ['type' => 'comment', 'id' => '5'],
                ['type' => 'comment', 'id' => '5'],
            ],
            $body['data'],
        );
    }

    #[Test]
    public function withMetaReturnsNewInstance(): void
    {
        $relatedResource = new StubResource(type: 'author', id: '1');

        $parentResource = new StubResource(
            type: 'article',
            id: '1',
            relationships: [
                'author' => static fn(mixed $object): ToOneRelationship => ToOneRelationship::create()
                    ->setData(new \stdClass(), $relatedResource),
            ],
        );

        $base = IdentifierResponse::forRelationship(
            parent: new \stdClass(),
            parentResource: $parentResource,
            relationshipName: 'author',
        );

        $withMeta = $base->withMeta(['total' => 1]);

        // withMeta must return a distinct instance (clone-then-assign immutability).
        self::assertNotSame($base, $withMeta);

        // Both instances still render valid relationship documents.
        $basePsr = $base->toPsrResponse(new StubServer(), StubJsonApiRequest::create());
        $withMetaPsr = $withMeta->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $basePsr->getStatusCode());
        self::assertSame(200, $withMetaPsr->getStatusCode());

        // The identifier data is present in both.
        $baseBody = $this->decode($basePsr->getBody()->getContents());
        $withMetaBody = $this->decode($withMetaPsr->getBody()->getContents());

        self::assertSame(['type' => 'author', 'id' => '1'], $baseBody['data']);
        self::assertSame(['type' => 'author', 'id' => '1'], $withMetaBody['data']);
    }

    #[Test]
    public function toOneNullRelationshipEmitsNullData(): void
    {
        $relatedResource = new StubResource(type: 'author', id: '1');

        $parentResource = new StubResource(
            type: 'article',
            id: '1',
            relationships: [
                'author' => static fn(mixed $object): ToOneRelationship => ToOneRelationship::create()
                    ->setData(null, $relatedResource),
            ],
        );

        $response = IdentifierResponse::forRelationship(
            parent: new \stdClass(),
            parentResource: $parentResource,
            relationshipName: 'author',
        );

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        // Empty to-one relationship: data must be null per the JSON:API spec.
        self::assertArrayHasKey('data', $body);
        self::assertNull($body['data']);
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
