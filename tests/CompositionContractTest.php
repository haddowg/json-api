<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests;

use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Tests\Double\Article;
use haddowg\JsonApi\Tests\Double\ArticleResourceHydrator;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Composition-contract proof.
 *
 * Verifies that both {@see \haddowg\JsonApi\Serializer\SerializerInterface} and
 * {@see \haddowg\JsonApi\Hydrator\HydratorInterface} are implementable **purely by
 * direct implementation — no inheritance from AbstractSerializer or AbstractHydrator**.
 *
 * A single fixture class ({@see ArticleResourceHydrator}) implements both interfaces
 * without extending any base class. These tests exercise its full hydrate→serialize
 * round-trip to confirm the contracts are complete and usable by composition.
 */
final class CompositionContractTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Structural assertions (inheritance checks)
    //
    // PHPStan knows at analysis time that ArticleResourceHydrator does not
    // extend either base class — that is exactly what we are proving. We use
    // class_parents() (which returns the runtime ancestor map) rather than a
    // ReflectionClass predicate so that a future accidental `extends` would
    // still be caught at test time without requiring PHPStan to already know
    // the hierarchy.
    // -------------------------------------------------------------------------

    #[Test]
    public function fixtureIsNotASubclassOfAbstractResource(): void
    {
        /** @var array<string, class-string> $ancestors */
        $ancestors = \class_parents(ArticleResourceHydrator::class) ?: [];
        self::assertArrayNotHasKey(
            AbstractSerializer::class,
            $ancestors,
            'ArticleResourceHydrator must NOT extend AbstractSerializer — ' .
            'the SerializerInterface contract must be implementable by composition alone.',
        );
    }

    #[Test]
    public function fixtureIsNotASubclassOfAbstractHydrator(): void
    {
        /** @var array<string, class-string> $ancestors */
        $ancestors = \class_parents(ArticleResourceHydrator::class) ?: [];
        self::assertArrayNotHasKey(
            AbstractHydrator::class,
            $ancestors,
            'ArticleResourceHydrator must NOT extend AbstractHydrator — ' .
            'the HydratorInterface contract must be implementable by composition alone.',
        );
    }

    // -------------------------------------------------------------------------
    // HydratorInterface contract
    // -------------------------------------------------------------------------

    #[Test]
    public function hydrateCreateBodyIntoArticle(): void
    {
        $body = [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'JSON:API Composition Works',
                    'authorId' => 'author-42',
                ],
            ],
        ];

        $request = $this->buildPostRequest($body);
        $fixture = new ArticleResourceHydrator();

        $result = $fixture->hydrate($request, new Article('', ''));

        self::assertInstanceOf(Article::class, $result);
        self::assertSame('JSON:API Composition Works', $result->title);
        self::assertSame('author-42', $result->authorId);
        // No client ID in the body → hydrator generates one.
        self::assertSame('generated-1', $result->id);
    }

    #[Test]
    public function hydrateCreateBodyWithClientGeneratedId(): void
    {
        $body = [
            'data' => [
                'type' => 'articles',
                'id' => 'client-id-99',
                'attributes' => [
                    'title' => 'Client ID Article',
                ],
            ],
        ];

        $request = $this->buildPostRequest($body);
        $result = (new ArticleResourceHydrator())->hydrate($request, new Article('', ''));

        self::assertInstanceOf(Article::class, $result);
        self::assertSame('client-id-99', $result->id);
        self::assertSame('Client ID Article', $result->title);
        self::assertNull($result->authorId);
    }

    // -------------------------------------------------------------------------
    // SerializerInterface contract (end-to-end via DataResponse)
    // -------------------------------------------------------------------------

    #[Test]
    public function serializeSingleArticleToJsonApiResponse(): void
    {
        $article = new Article('1', 'My First Article', 'author-7');
        $fixture = new ArticleResourceHydrator();
        $server = new StubServer();
        $request = StubJsonApiRequest::create();

        $psrResponse = DataResponse::fromResource($article, $fixture)
            ->toPsrResponse($server, $request);

        self::assertSame(200, $psrResponse->getStatusCode());
        self::assertSame('application/vnd.api+json', $psrResponse->getHeaderLine('Content-Type'));

        $body = $this->decodeBody($psrResponse->getBody()->getContents());

        self::assertArrayHasKey('data', $body);
        /** @var array<string, mixed> $data */
        $data = $body['data'];
        self::assertIsArray($data);

        self::assertSame('articles', $data['type']);
        self::assertSame('1', $data['id']);

        self::assertArrayHasKey('attributes', $data);
        /** @var array<string, mixed> $attrs */
        $attrs = $data['attributes'];
        self::assertIsArray($attrs);
        self::assertSame('My First Article', $attrs['title']);
        self::assertSame('author-7', $attrs['authorId']);
    }

    #[Test]
    public function serializeCollectionOfArticlesToJsonApiResponse(): void
    {
        $articles = [
            new Article('10', 'Article Ten', null),
            new Article('11', 'Article Eleven', 'author-2'),
        ];

        $fixture = new ArticleResourceHydrator();
        $server = new StubServer();
        $request = StubJsonApiRequest::create();

        $psrResponse = DataResponse::fromCollection($articles, $fixture)
            ->toPsrResponse($server, $request);

        self::assertSame(200, $psrResponse->getStatusCode());

        $body = $this->decodeBody($psrResponse->getBody()->getContents());

        self::assertArrayHasKey('data', $body);
        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);

        self::assertSame('articles', $data[0]['type']);
        self::assertSame('10', $data[0]['id']);
        /** @var array<string, mixed> $attrs0 */
        $attrs0 = $data[0]['attributes'];
        self::assertSame('Article Ten', $attrs0['title']);
        self::assertNull($attrs0['authorId']);

        self::assertSame('articles', $data[1]['type']);
        self::assertSame('11', $data[1]['id']);
        /** @var array<string, mixed> $attrs1 */
        $attrs1 = $data[1]['attributes'];
        self::assertSame('Article Eleven', $attrs1['title']);
        self::assertSame('author-2', $attrs1['authorId']);
    }

    // -------------------------------------------------------------------------
    // Full round-trip: hydrate a POST body, then serialize the result
    // -------------------------------------------------------------------------

    #[Test]
    public function hydrateAndSerializeRoundTrip(): void
    {
        $createBody = [
            'data' => [
                'type' => 'articles',
                'id' => 'round-trip-id',
                'attributes' => [
                    'title' => 'Round-Trip Article',
                    'authorId' => 'author-rt',
                ],
            ],
        ];

        $fixture = new ArticleResourceHydrator();
        $postRequest = $this->buildPostRequest($createBody);

        /** @var Article $article */
        $article = $fixture->hydrate($postRequest, new Article('', ''));

        // Verify hydration
        self::assertSame('round-trip-id', $article->id);
        self::assertSame('Round-Trip Article', $article->title);
        self::assertSame('author-rt', $article->authorId);

        // Now serialize the hydrated object back out
        $psrResponse = DataResponse::fromResource($article, $fixture)
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decodeBody($psrResponse->getBody()->getContents());

        /** @var array<string, mixed> $data */
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('articles', $data['type']);
        self::assertSame('round-trip-id', $data['id']);

        /** @var array<string, mixed> $attrs */
        $attrs = $data['attributes'];
        self::assertIsArray($attrs);
        self::assertSame('Round-Trip Article', $attrs['title']);
        self::assertSame('author-rt', $attrs['authorId']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function buildPostRequest(array $body): JsonApiRequest
    {
        $psrRequest = (new ServerRequest('POST', '/articles'))
            ->withHeader('Content-Type', 'application/vnd.api+json')
            ->withParsedBody($body);

        return new JsonApiRequest($psrRequest);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
