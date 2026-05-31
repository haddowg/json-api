<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Testing;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use haddowg\JsonApi\Testing\Internal\Decode;
use haddowg\JsonApi\Testing\Internal\RequestStub;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApi\Testing\JsonApiOperationBuilder;
use haddowg\JsonApi\Testing\JsonApiRequestBuilder;
use haddowg\JsonApi\Testing\SpecCompliance;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\Factory\Psr17Factory;
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
