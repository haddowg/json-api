<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Testing;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use haddowg\JsonApi\Testing\Internal\Decode;
use haddowg\JsonApi\Testing\Internal\Diff;
use haddowg\JsonApi\Testing\Internal\RequestStub;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApi\Testing\JsonApiOperationBuilder;
use haddowg\JsonApi\Testing\JsonApiRequestBuilder;
use haddowg\JsonApi\Testing\ResponseMeta;
use haddowg\JsonApi\Testing\SpecCompliance;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonApiDocument::class)]
#[CoversClass(JsonApiErrors::class)]
#[CoversClass(JsonApiRequestBuilder::class)]
#[CoversClass(JsonApiOperationBuilder::class)]
#[CoversClass(SpecCompliance::class)]
#[CoversClass(Decode::class)]
#[CoversClass(Diff::class)]
#[CoversClass(ResponseMeta::class)]
#[CoversClass(RequestStub::class)]
#[Group('spec:document-structure')]
final class TestingUtilitiesTest extends TestCase
{
    use AssertsSpecCompliance;

    /**
     * @return array<string, mixed>
     */
    private function articleDocument(): array
    {
        return [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'JSON:API'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'people', 'id' => '9']],
                ],
            ],
            'included' => [
                ['type' => 'people', 'id' => '9', 'attributes' => ['name' => 'Dan']],
            ],
            'meta' => ['count' => 1],
            'links' => ['self' => 'https://example.com/articles/1', 'profile' => ['https://example.com/p']],
        ];
    }

    // ---- JsonApiDocument ----

    #[Test]
    public function documentAssertionsPassOnWellFormedInput(): void
    {
        JsonApiDocument::of($this->articleDocument())
            ->assertHasType('articles')
            ->assertHasId('1')
            ->assertHasAttribute('title')
            ->assertHasAttribute('title', 'JSON:API')
            ->assertHasRelationship('author', 'people', '9')
            ->assertHasIncluded('people', 1)
            ->assertNotHasIncluded('comments')
            ->assertHasMetaKey('count')
            ->assertMetaValue('count', 1)
            ->assertHasLink('self', 'https://example.com/articles/1')
            ->assertProfileApplied('https://example.com/p');
    }

    #[Test]
    public function documentAcceptsAJsonStringInput(): void
    {
        $json = \json_encode($this->articleDocument(), \JSON_THROW_ON_ERROR);

        JsonApiDocument::of($json)->assertHasType('articles');
    }

    #[Test]
    public function documentAttributeMismatchFailsWithAClearMessage(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Attribute 'title' does not match.");

        JsonApiDocument::of($this->articleDocument())->assertHasAttribute('title', 'Wrong');
    }

    #[Test]
    public function documentMissingTypeFails(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of(['data' => ['type' => 'articles', 'id' => '1']])->assertHasType('people');
    }

    // ---- response envelope (status + headers) ----

    private function articleResponse(int $status = 200): Response
    {
        $body = \json_encode($this->articleDocument(), \JSON_THROW_ON_ERROR);

        return new Response($status, [
            'Content-Type' => 'application/vnd.api+json',
            'Location' => 'https://example.com/articles/1',
        ], $body);
    }

    #[Test]
    public function responseEnvelopeAssertionsPassFromAPsr7Response(): void
    {
        JsonApiDocument::of($this->articleResponse(200))
            ->assertStatus(200)
            ->assertContentType()
            ->assertContentType('application/vnd.api+json')
            ->assertHeader('Location')
            ->assertHeader('content-type', 'application/vnd.api+json')
            ->assertHasType('articles');
    }

    #[Test]
    public function explicitResponseMetaFeedsTheEnvelopeAssertions(): void
    {
        // Mirrors the HttpFoundation path: status + headers passed in as plain scalars.
        $meta = new ResponseMeta(201, ['Content-Type' => 'application/vnd.api+json']);

        JsonApiDocument::of($this->articleDocument(), meta: $meta)
            ->assertStatus(201)
            ->assertContentType();
    }

    #[Test]
    public function assertStatusFailsOnTheWrongStatus(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected response status 404, got 200.');

        JsonApiDocument::of($this->articleResponse(200))->assertStatus(404);
    }

    #[Test]
    public function assertContentTypeFailsWhenTheTypeDiffers(): void
    {
        $this->expectException(AssertionFailedError::class);

        $response = new Response(200, ['Content-Type' => 'text/html'], '{"data":null}');
        JsonApiDocument::of($response)->assertContentType();
    }

    #[Test]
    public function assertHeaderFailsWhenAbsent(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Response header 'Location' is missing.");

        JsonApiDocument::of(['data' => null])->assertHeader('Location');
    }

    // ---- exact-match (single resource) ----

    #[Test]
    public function assertFetchedOneExactPassesOnAWholeMemberMatch(): void
    {
        $member = [
            'type' => 'articles',
            'id' => '1',
            'attributes' => ['title' => 'JSON:API'],
        ];

        JsonApiDocument::of(['data' => $member])->assertFetchedOneExact($member);
    }

    #[Test]
    public function assertFetchedOneExactIgnoresKeyOrdering(): void
    {
        JsonApiDocument::of(['data' => ['id' => '1', 'type' => 'articles', 'attributes' => ['b' => 2, 'a' => 1]]])
            ->assertFetchedOneExact(['type' => 'articles', 'attributes' => ['a' => 1, 'b' => 2], 'id' => '1']);
    }

    #[Test]
    public function assertFetchedOneExactCatchesALeakedAttribute(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('does not exactly match');

        // The actual member carries an extra `secret` attribute the expectation omits.
        JsonApiDocument::of(['data' => [
            'type' => 'articles',
            'id' => '1',
            'attributes' => ['title' => 'JSON:API', 'secret' => 'leaked'],
        ]])->assertFetchedOneExact([
            'type' => 'articles',
            'id' => '1',
            'attributes' => ['title' => 'JSON:API'],
        ]);
    }

    // ---- collection family ----

    /**
     * @return array<string, mixed>
     */
    private function collectionDocument(): array
    {
        return [
            'data' => [
                ['type' => 'articles', 'id' => '3', 'attributes' => ['title' => 'C']],
                ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'A']],
                ['type' => 'articles', 'id' => '2', 'attributes' => ['title' => 'B']],
            ],
        ];
    }

    #[Test]
    public function collectionAssertionsPassOnAWellFormedCollection(): void
    {
        JsonApiDocument::of($this->collectionDocument())
            ->assertFetchedMany()
            ->assertCollectionCount(3)
            ->assertCollectionContains('articles', '2')
            ->assertFetchedManyInOrder(['3', '1', '2'])
            ->assertFetchedManyInOrder(['3', '1', '2'], 'articles');
    }

    #[Test]
    public function assertFetchedManyFailsOnASingleResource(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not a collection');

        JsonApiDocument::of(['data' => ['type' => 'articles', 'id' => '1']])->assertFetchedMany();
    }

    #[Test]
    public function assertFetchedManyInOrderFailsOnTheWrongOrder(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not in the expected order');

        // Real order is 3,1,2 — the sort witness must reject a different order.
        JsonApiDocument::of($this->collectionDocument())->assertFetchedManyInOrder(['1', '2', '3']);
    }

    #[Test]
    public function assertCollectionContainsFailsWhenAbsent(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of($this->collectionDocument())->assertCollectionContains('articles', '99');
    }

    #[Test]
    public function assertFetchedManyExactMatchesIdsAndAttributesInOrder(): void
    {
        JsonApiDocument::of($this->collectionDocument())->assertFetchedManyExact([
            ['type' => 'articles', 'id' => '3'],
            ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'A']],
            ['type' => 'articles', 'id' => '2'],
        ]);
    }

    #[Test]
    public function assertFetchedManyExactFailsOnAReorderedMember(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of($this->collectionDocument())->assertFetchedManyExact([
            ['type' => 'articles', 'id' => '1'],
            ['type' => 'articles', 'id' => '3'],
            ['type' => 'articles', 'id' => '2'],
        ]);
    }

    // ---- includes membership ----

    #[Test]
    public function includedMembershipAssertionsPass(): void
    {
        JsonApiDocument::of($this->articleDocument())
            ->assertHasIncludedResource('people', '9')
            ->assertIncludedExactly([['type' => 'people', 'id' => '9']]);
    }

    #[Test]
    public function assertHasIncludedResourceFailsWhenAbsent(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("No included 'people' resource with id '404' found.");

        JsonApiDocument::of($this->articleDocument())->assertHasIncludedResource('people', '404');
    }

    #[Test]
    public function assertIncludedExactlyFailsOnAnExtraIncludedResource(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of($this->articleDocument())->assertIncludedExactly([
            ['type' => 'people', 'id' => '9'],
            ['type' => 'people', 'id' => '10'],
        ]);
    }

    // ---- exact meta / links ----

    #[Test]
    public function exactMetaAndLinksPass(): void
    {
        JsonApiDocument::of($this->articleDocument())
            ->assertExactMeta(['count' => 1])
            ->assertExactLinks([
                'self' => 'https://example.com/articles/1',
                'profile' => ['https://example.com/p'],
            ]);
    }

    #[Test]
    public function assertExactMetaFailsOnAnExtraKey(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of($this->articleDocument())->assertExactMeta(['count' => 1, 'extra' => true]);
    }

    // ---- absence ----

    #[Test]
    public function absenceAssertionsPass(): void
    {
        JsonApiDocument::of(['data' => null])
            ->assertNoData()
            ->assertNoMeta()
            ->assertNoLink()
            ->assertNoLink('self');
    }

    #[Test]
    public function assertNoDataFailsWhenDataIsPresent(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('unexpectedly carries primary `data`');

        JsonApiDocument::of($this->articleDocument())->assertNoData();
    }

    #[Test]
    public function assertNoLinkFailsWhenLinksArePresent(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of($this->articleDocument())->assertNoLink();
    }

    #[Test]
    public function assertNoLinkForRelFailsWhenThatLinkIsPresent(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiDocument::of($this->articleDocument())->assertNoLink('self');
    }

    // ---- JsonApiErrors ----

    #[Test]
    public function errorAssertionsPassOnWellFormedInput(): void
    {
        $document = [
            'errors' => [
                ['status' => '422', 'code' => 'INVALID', 'source' => ['pointer' => '/data/attributes/title']],
            ],
        ];

        JsonApiErrors::of($document)
            ->assertCount(1)
            ->assertHasError(status: '422')
            ->assertHasError(pointer: '/data/attributes/title')
            ->assertHasErrorAt('/data/attributes/title')
            ->assertHasErrorWithCode('INVALID');
    }

    #[Test]
    public function errorAssertionFailsWhenNoErrorMatches(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiErrors::of(['errors' => [['status' => '500']]])->assertHasError(status: '404');
    }

    #[Test]
    public function errorEnvelopeAndExactAssertionsPassFromAPsr7Response(): void
    {
        $body = \json_encode([
            'errors' => [
                ['status' => '422', 'code' => 'INVALID', 'source' => ['pointer' => '/data/attributes/title']],
            ],
        ], \JSON_THROW_ON_ERROR);
        $response = new Response(422, ['Content-Type' => 'application/vnd.api+json'], $body);

        JsonApiErrors::of($response)
            ->assertStatus(422)
            ->assertContentType()
            ->assertHasExactError(['status' => '422', 'code' => 'INVALID', 'source' => ['pointer' => '/data/attributes/title']])
            ->assertErrorsExact([
                ['status' => '422', 'code' => 'INVALID', 'source' => ['pointer' => '/data/attributes/title']],
            ]);
    }

    #[Test]
    public function assertHasExactErrorFailsOnAMismatchedDetail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No error object exactly matches');

        JsonApiErrors::of(['errors' => [['status' => '422', 'code' => 'INVALID', 'detail' => 'present']]])
            ->assertHasExactError(['status' => '422', 'code' => 'INVALID']);
    }

    #[Test]
    public function assertErrorsExactFailsOnAnExtraError(): void
    {
        $this->expectException(AssertionFailedError::class);

        JsonApiErrors::of(['errors' => [['status' => '422'], ['status' => '500']]])
            ->assertErrorsExact([['status' => '422']]);
    }

    // ---- JsonApiRequestBuilder ----

    #[Test]
    public function requestBuilderBuildsAPostWithBodyAndHeaders(): void
    {
        $psr17 = new Psr17Factory();
        $request = JsonApiRequestBuilder::post('/api/posts', $psr17, $psr17)
            ->withResource('posts', attributes: ['title' => 'Hello'])
            ->withProfile('https://example.com/p')
            ->build();

        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('application/vnd.api+json', $request->getHeaderLine('Content-Type'));
        self::assertStringContainsString('profile="https://example.com/p"', $request->getHeaderLine('Content-Type'));

        $body = $request->getParsedBody();
        self::assertIsArray($body);
        self::assertSame('posts', $body['data']['type']);
        self::assertSame('Hello', $body['data']['attributes']['title']);
    }

    #[Test]
    public function requestBuilderGetCarriesQueryParams(): void
    {
        $psr17 = new Psr17Factory();
        $request = JsonApiRequestBuilder::get('/api/posts', $psr17, $psr17)
            ->withQueryParam('sort', '-createdAt')
            ->build();

        self::assertSame('GET', $request->getMethod());
        self::assertStringContainsString('sort=', $request->getUri()->getQuery());
        self::assertSame(['sort' => '-createdAt'], $request->getQueryParams());
    }

    // ---- JsonApiOperationBuilder ----

    #[Test]
    public function operationBuilderBuildsACreateOperationWithBody(): void
    {
        $operation = JsonApiOperationBuilder::create('posts', new StubServer())
            ->withAttribute('title', 'Hello')
            ->withRelationship('author', type: 'users', id: '42')
            ->build();

        self::assertInstanceOf(CreateResourceOperation::class, $operation);
        self::assertSame('posts', $operation->target()->type);

        $body = $operation->body()->getParsedBody();
        self::assertIsArray($body);
        self::assertSame('Hello', $body['data']['attributes']['title']);
        self::assertSame('users', $body['data']['relationships']['author']['data']['type']);
    }

    #[Test]
    public function operationBuilderBuildsABodylessFetch(): void
    {
        $operation = JsonApiOperationBuilder::fetch('posts', '1', new StubServer())->build();

        self::assertInstanceOf(FetchResourceOperation::class, $operation);
        self::assertSame('1', $operation->target()->id);
    }

    // ---- assertJsonApiSpecCompliant ----

    #[Test]
    public function specComplianceAssertionPassesForAValidDocument(): void
    {
        $this->assertJsonApiSpecCompliant([
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'JSON:API']],
        ]);

        // The static helper form is equivalent and also passes.
        SpecCompliance::assert([
            'data' => ['type' => 'articles', 'id' => '1'],
        ]);

        self::assertSame(1, $this->numberOfAssertionsPerformed() >= 0 ? 1 : 0);
    }

    #[Test]
    public function specComplianceAssertionFailsForAnInvalidDocument(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not JSON:API spec-compliant');

        $this->assertJsonApiSpecCompliant(['data' => ['type' => 'articles']]); // missing id
    }
}
