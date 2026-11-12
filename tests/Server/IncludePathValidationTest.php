<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Transformer\IncludePathGuard;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * `?include` is validated against the primary type's relation tree before any data
 * exists, so an empty collection answers an unknown path with the same `400` a
 * populated one does. A relationship endpoint's paths start at the parent. Driven
 * through {@see Server::handle()}, with a handler that always answers with no data,
 * and through {@see Server::dispatch()}.
 */
#[CoversClass(IncludePathGuard::class)]
#[CoversClass(Server::class)]
#[Group('spec:inclusion-of-related-resources')]
final class IncludePathValidationTest extends TestCase
{
    #[Test]
    public function anUnknownPathOnAnEmptyCollectionIsRejected(): void
    {
        $response = $this->get('/articles?include=bogus', new Target('articles'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED', "Included paths 'bogus' can't be recognized by the endpoint!");
    }

    #[Test]
    public function aValidPathOnAnEmptyCollectionIsAccepted(): void
    {
        $response = $this->get('/articles?include=author,comments', new Target('articles'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->decode($response)['data']);
    }

    #[Test]
    public function aValidNestedPathOnAnEmptyCollectionIsAccepted(): void
    {
        $response = $this->get('/articles?include=author.publisher', new Target('articles'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function anUnknownNestedPathIsRejectedWithItsFullPath(): void
    {
        $response = $this->get('/articles?include=author.bogus', new Target('articles'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED', "Included paths 'author.bogus' can't be recognized by the endpoint!");
    }

    #[Test]
    public function anUnconditionallyHiddenRelationIsUnknown(): void
    {
        $response = $this->get('/articles?include=internalNotes', new Target('articles'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED');
    }

    #[Test]
    public function aSparseByDefaultRelationIsUnknownUnlessTheFieldsetNamesIt(): void
    {
        $this->assertError(
            $this->get('/articles?include=reviewers', new Target('articles')),
            400,
            'INCLUSION_UNRECOGNIZED',
        );

        self::assertSame(
            200,
            $this->get('/articles?include=reviewers&fields[articles]=reviewers', new Target('articles'))->getStatusCode(),
        );
    }

    #[Test]
    public function aPathThroughAPolymorphicRelationIsValidWhenAnyRelatedTypeHasIt(): void
    {
        // `commentable` is articles|videos: only articles declare `author`, only
        // videos declare `channel`. Either is a path some member could satisfy.
        self::assertSame(200, $this->get('/comments?include=commentable.author', new Target('comments'))->getStatusCode());
        self::assertSame(200, $this->get('/comments?include=commentable.channel', new Target('comments'))->getStatusCode());
    }

    #[Test]
    public function aPathThroughAPolymorphicRelationIsRejectedWhenNoRelatedTypeHasIt(): void
    {
        $response = $this->get('/comments?include=commentable.bogus', new Target('comments'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED', "Included paths 'commentable.bogus' can't be recognized by the endpoint!");
    }

    #[Test]
    public function aRelatedEndpointValidatesAgainstTheRelatedType(): void
    {
        // The related endpoint's primary data is `people`, so `publisher` is valid
        // there and `comments` (an articles relation) is not.
        self::assertSame(
            200,
            $this->get('/articles/1/author?include=publisher', new Target('articles', '1', 'author'))->getStatusCode(),
        );
        $this->assertError(
            $this->get('/articles/1/author?include=comments', new Target('articles', '1', 'author')),
            400,
            'INCLUSION_UNRECOGNIZED',
        );
    }

    #[Test]
    public function aNonIncludableRelationIsRejectedOnAnEmptyCollection(): void
    {
        $response = $this->get('/articles?include=editor', new Target('articles'));

        $this->assertError($response, 400, 'INCLUSION_NOT_ALLOWED');
    }

    #[Test]
    public function aRelationNonIncludableOnlyByPredicateIsLeftToRenderTime(): void
    {
        // A per-request predicate may read the domain object, so with no object
        // there is nothing to evaluate; a populated response still runs it.
        $response = $this->get('/articles?include=draftEditor', new Target('articles'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function thePathWhitelistIsEnforcedOnAnEmptyCollection(): void
    {
        $response = $this->get('/guarded-articles?include=comments', new Target('guarded-articles'));

        $this->assertError($response, 400, 'INCLUSION_NOT_ALLOWED');
    }

    #[Test]
    public function thePathWhitelistWinsOverAnUnknownPath(): void
    {
        // The whitelist ran before rendering already, so an unlisted path that is
        // also unknown keeps the error it had.
        $response = $this->get('/guarded-articles?include=bogus', new Target('guarded-articles'));

        $this->assertError($response, 400, 'INCLUSION_NOT_ALLOWED');
    }

    #[Test]
    public function theMaxIncludeDepthIsEnforcedOnAnEmptyCollection(): void
    {
        $response = $this->get('/articles?include=author.publisher', new Target('articles'), maxIncludeDepth: 1);

        $this->assertError($response, 400, 'INCLUSION_DEPTH_EXCEEDED');
    }

    #[Test]
    public function theMaxIncludeDepthWinsOverAnUnknownPath(): void
    {
        $response = $this->get('/articles?include=author.bogus', new Target('articles'), maxIncludeDepth: 1);

        $this->assertError($response, 400, 'INCLUSION_DEPTH_EXCEEDED');
    }

    #[Test]
    public function relaxedStrictModeStillRejectsAnUnknownInclude(): void
    {
        $response = $this->get('/articles?include=bogus', new Target('articles'), strict: false);

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED');
    }

    #[Test]
    public function aRelationshipReadRootsItsPathsAtTheParent(): void
    {
        // The spec's own example: the paths start at the parent, through the relationship.
        $response = $this->get('/articles/1/relationships/comments?include=comments.commentable', $this->relationship('comments'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            200,
            $this->get('/articles/1/relationships/comments?include=author.publisher', $this->relationship('comments'))->getStatusCode(),
            'a known path on another branch is accepted',
        );
    }

    #[Test]
    public function aRelationshipReadRejectsAnUnknownTopLevelPath(): void
    {
        $response = $this->get('/articles/1/relationships/comments?include=bogus', $this->relationship('comments'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED', "Included paths 'bogus' can't be recognized by the endpoint!");
    }

    #[Test]
    public function aRelationshipReadRejectsAnUnknownPathOnAnotherBranch(): void
    {
        $response = $this->get('/articles/1/relationships/comments?include=author.bogus', $this->relationship('comments'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED', "Included paths 'author.bogus' can't be recognized by the endpoint!");
    }

    #[Test]
    public function aRelationshipReadRejectsAnUnknownPathUnderItsRelationship(): void
    {
        $response = $this->get('/articles/1/relationships/comments?include=comments.bogus', $this->relationship('comments'));

        $this->assertError($response, 400, 'INCLUSION_UNRECOGNIZED', "Included paths 'comments.bogus' can't be recognized by the endpoint!");
    }

    #[Test]
    public function aRelationshipReadAppliesTheParentsSafeguards(): void
    {
        $this->assertError(
            $this->get('/articles/1/relationships/comments?include=editor', $this->relationship('comments')),
            400,
            'INCLUSION_NOT_ALLOWED',
        );
        $this->assertError(
            $this->get('/guarded-articles/1/relationships/comments?include=comments', new Target('guarded-articles', '1', 'comments', true)),
            400,
            'INCLUSION_NOT_ALLOWED',
            null,
            'the parent\'s allowed include paths apply',
        );
        $this->assertError(
            $this->get('/articles/1/relationships/comments?include=author.publisher', $this->relationship('comments'), maxIncludeDepth: 1),
            400,
            'INCLUSION_DEPTH_EXCEEDED',
        );
    }

    #[Test]
    public function anUndeclaredRelationKeepsItsNotFound(): void
    {
        $response = $this->get('/articles/1/relationships/bogus?include=bogus', $this->relationship('bogus'));

        self::assertSame(404, $response->getStatusCode());
        $errors = $this->decode($response)['errors'];
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame('RELATIONSHIP_NOT_EXISTS', $errors[0]['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relationshipMutations(): iterable
    {
        yield 'replace' => ['PATCH'];
        yield 'add' => ['POST'];
        yield 'remove' => ['DELETE'];
    }

    #[Test]
    #[DataProvider('relationshipMutations')]
    public function aRelationshipMutationChecksItsPathsLikeTheRead(string $method): void
    {
        self::assertSame(200, $this->get('/articles/1/relationships/comments?include=comments.commentable', $this->relationship('comments'), method: $method)->getStatusCode());
        $this->assertError(
            $this->get('/articles/1/relationships/comments?include=bogus', $this->relationship('comments'), method: $method),
            400,
            'INCLUSION_UNRECOGNIZED',
        );
        $this->assertError(
            $this->get('/articles/1/relationships/comments?include=author.bogus', $this->relationship('comments'), method: $method),
            400,
            'INCLUSION_UNRECOGNIZED',
        );
        $this->assertError(
            $this->get('/articles/1/relationships/comments?include=author.publisher', $this->relationship('comments'), maxIncludeDepth: 1, method: $method),
            400,
            'INCLUSION_DEPTH_EXCEEDED',
        );
    }

    #[Test]
    #[DataProvider('relationshipMutations')]
    public function aWriteOnlyRelationsMutationIgnoresInclude(string $method): void
    {
        // It answers 204 with no document, so like a delete it has nothing to include into.
        $response = $this->get('/articles/1/relationships/watchers?include=bogus', $this->relationship('watchers'), method: $method);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function dispatchChecksARelationshipReadBeforeTheHandlerRuns(): void
    {
        $this->expectException(InclusionUnrecognized::class);

        $this->dispatch('GET', '/articles/1/relationships/comments?include=author.bogus', $this->relationship('comments'));
    }

    #[Test]
    #[DataProvider('relationshipMutations')]
    public function dispatchChecksARelationshipMutationBeforeTheHandlerRuns(string $method): void
    {
        $this->expectException(InclusionUnrecognized::class);

        $this->dispatch($method, '/articles/1/relationships/comments?include=bogus', $this->relationship('comments'));
    }

    #[Test]
    public function dispatchLeavesAWriteOnlyRelationsMutationToAnswer(): void
    {
        $response = $this->dispatch('PATCH', '/articles/1/relationships/watchers?include=bogus', $this->relationship('watchers'));

        self::assertInstanceOf(IdentifierResponse::class, $response);
    }

    private function relationship(string $name): Target
    {
        return new Target('articles', '1', $name, true);
    }

    private function dispatch(string $method, string $uri, Target $target): mixed
    {
        $server = $this->server(null, true);
        $request = new JsonApiRequest($this->request($method, $uri, $target));

        return $server->dispatch((new OperationFactory())->fromRequest($request, $target, new OperationContext($server, $request)));
    }

    private function get(string $uri, Target $target, ?int $maxIncludeDepth = null, bool $strict = true, string $method = 'GET'): ResponseInterface
    {
        $server = $this->server($maxIncludeDepth, $strict);

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($this->request($method, $uri, $target));
    }

    private function request(string $method, string $uri, Target $target): ServerRequest
    {
        $parts = \parse_url($uri);
        \parse_str($parts['query'] ?? '', $query);

        return (new ServerRequest($method, $uri))
            ->withQueryParams($query)
            ->withAttribute(Target::class, $target);
    }

    private function server(?int $maxIncludeDepth, bool $strict): Server
    {
        $psr17 = new Psr17Factory();
        $handler = new class implements OperationHandlerInterface {
            public function handle(JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse
            {
                $server = $operation->context()->server;
                $target = $operation->target();

                return match (true) {
                    $operation instanceof FetchRelatedOperation => RelatedResponse::fromResource(null, $server->serializerFor('people')),
                    $operation instanceof FetchResourceOperation => DataResponse::fromCollection([], $server->serializerFor($target->type)),
                    $target->isRelationshipEndpoint => IdentifierResponse::forRelationship(['id' => '1'], $server->serializerFor($target->type), (string) $target->relationship),
                    default => DataResponse::fromResource(null, $server->serializerFor($target->type)),
                };
            }
        };

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMaxIncludeDepth($maxIncludeDepth)
            ->withStrictQueryParameters($strict)
            ->register(IncludeArticle::class)
            ->register(IncludeGuardedArticle::class)
            ->register(IncludePerson::class)
            ->register(IncludePublisher::class)
            ->register(IncludeComment::class)
            ->register(IncludeVideo::class)
            ->register(IncludeChannel::class)
            ->withHandler($handler);
    }

    private function assertError(ResponseInterface $response, int $status, string $code, ?string $detail = null, string $message = ''): void
    {
        self::assertSame($status, $response->getStatusCode(), $message);

        $errors = $this->decode($response)['errors'];
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame($code, $errors[0]['code']);
        self::assertSame(['parameter' => 'include'], $errors[0]['source']);
        if ($detail !== null) {
            self::assertSame($detail, $errors[0]['detail']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}

final class IncludeArticle extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'people'),
            HasMany::make('comments', 'comments'),
            BelongsTo::make('editor', 'people')->cannotBeIncluded(),
            BelongsTo::make('draftEditor', 'people')->cannotBeIncluded(static fn(mixed $article): bool => true),
            HasMany::make('internalNotes', 'comments')->hidden(),
            HasMany::make('reviewers', 'people')->sparseByDefault(),
            HasMany::make('watchers', 'people')->writeOnly(),
        ];
    }
}

final class IncludeGuardedArticle extends AbstractResource
{
    public static string $type = 'guarded-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('author', 'people'),
            HasMany::make('comments', 'comments'),
        ];
    }

    /**
     * @return list<string>
     */
    public function getAllowedIncludePaths(): array
    {
        return ['author'];
    }
}

final class IncludePerson extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('publisher', 'publishers'),
        ];
    }
}

final class IncludePublisher extends AbstractResource
{
    public static string $type = 'publishers';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}

final class IncludeComment extends AbstractResource
{
    public static string $type = 'comments';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body'),
            MorphTo::make('commentable', ['articles', 'videos']),
        ];
    }
}

final class IncludeVideo extends AbstractResource
{
    public static string $type = 'videos';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('channel', 'channels'),
        ];
    }
}

final class IncludeChannel extends AbstractResource
{
    public static string $type = 'channels';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
