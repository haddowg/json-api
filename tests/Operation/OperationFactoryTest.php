<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:fetching-data')]
final class OperationFactoryTest extends TestCase
{
    #[Test]
    public function getCollectionBuildsAFetchResourceOperation(): void
    {
        $request = $this->request('GET', '/articles');
        $target = new Target('articles');
        $context = $this->context($request);

        $operation = (new OperationFactory())->fromRequest($request, $target, $context);

        self::assertInstanceOf(FetchResourceOperation::class, $operation);
        self::assertSame($target, $operation->target());
        self::assertSame($context, $operation->context());
    }

    #[Test]
    public function getSingleResourceStillMapsToFetchResourceWithoutAHasIdBranch(): void
    {
        $request = $this->request('GET', '/articles/1');
        $target = new Target('articles', '1');

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(FetchResourceOperation::class, $operation);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function getRelationshipEndpointBuildsAFetchRelationshipOperation(): void
    {
        $request = $this->request('GET', '/articles/1/relationships/author');
        $target = new Target('articles', '1', 'author', isRelationshipEndpoint: true);

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(FetchRelationshipOperation::class, $operation);
    }

    #[Test]
    public function getRelatedEndpointBuildsAFetchRelatedOperation(): void
    {
        $request = $this->request('GET', '/articles/1/author');
        $target = new Target('articles', '1', 'author', isRelationshipEndpoint: false);

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(FetchRelatedOperation::class, $operation);
    }

    #[Test]
    #[Group('spec:crud')]
    public function postWithoutRelationshipBuildsACreateResourceOperationCarryingTheRequestAsBody(): void
    {
        $request = $this->request('POST', '/articles');
        $target = new Target('articles');

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(CreateResourceOperation::class, $operation);
        self::assertSame($request, $operation->body());
    }

    #[Test]
    #[Group('spec:crud')]
    public function postWithRelationshipBuildsAnAddToRelationshipOperation(): void
    {
        $request = $this->request('POST', '/articles/1/relationships/tags');
        $target = new Target('articles', '1', 'tags', isRelationshipEndpoint: true);

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(AddToRelationshipOperation::class, $operation);
        self::assertSame($request, $operation->body());
    }

    #[Test]
    #[Group('spec:crud')]
    public function patchWithoutRelationshipBuildsAnUpdateResourceOperation(): void
    {
        $request = $this->request('PATCH', '/articles/1');
        $target = new Target('articles', '1');

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(UpdateResourceOperation::class, $operation);
        self::assertSame($request, $operation->body());
    }

    #[Test]
    #[Group('spec:crud')]
    public function patchWithRelationshipBuildsAnUpdateRelationshipOperation(): void
    {
        $request = $this->request('PATCH', '/articles/1/relationships/author');
        $target = new Target('articles', '1', 'author', isRelationshipEndpoint: true);

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(UpdateRelationshipOperation::class, $operation);
        self::assertSame($request, $operation->body());
    }

    #[Test]
    #[Group('spec:crud')]
    public function deleteWithoutRelationshipBuildsABodyLessDeleteResourceOperation(): void
    {
        $request = $this->request('DELETE', '/articles/1');
        $target = new Target('articles', '1');

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(DeleteResourceOperation::class, $operation);
    }

    #[Test]
    #[Group('spec:crud')]
    public function deleteWithRelationshipBuildsABodyCarryingRemoveFromRelationshipOperation(): void
    {
        $request = $this->request('DELETE', '/articles/1/relationships/tags');
        $target = new Target('articles', '1', 'tags', isRelationshipEndpoint: true);

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(RemoveFromRelationshipOperation::class, $operation);
        self::assertSame($request, $operation->body());
    }

    #[Test]
    public function theReturnedOperationCarriesTheExplicitTargetAndContext(): void
    {
        $request = $this->request('GET', '/articles/1');
        $target = new Target('articles', '1');
        $context = $this->context($request);

        $operation = (new OperationFactory())->fromRequest($request, $target, $context);

        self::assertSame($target, $operation->target());
        self::assertSame($context, $operation->context());
    }

    #[Test]
    #[Group('spec:query-parameters')]
    public function queryParametersAreBuiltFromTheRequestInternally(): void
    {
        $request = $this->request('GET', '/articles?include=author&sort=-title');
        $target = new Target('articles');

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        $query = $operation->queryParameters();
        self::assertSame(['author'], $query->includes);
        self::assertSame(['-title'], $query->sort);
    }

    #[Test]
    public function anUnsupportedMethodThrowsApplicationError(): void
    {
        $request = $this->request('PUT', '/articles/1');
        $target = new Target('articles', '1');

        $this->expectException(ApplicationError::class);

        (new OperationFactory())->fromRequest($request, $target, $this->context($request));
    }

    #[Test]
    #[Group('spec:crud')]
    public function methodMatchingIsCaseInsensitive(): void
    {
        $request = $this->request('post', '/articles');
        $target = new Target('articles');

        $operation = (new OperationFactory())->fromRequest($request, $target, $this->context($request));

        self::assertInstanceOf(CreateResourceOperation::class, $operation);
    }

    private function request(string $method, string $uri): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest($method, $uri));
    }

    private function context(JsonApiRequestInterface $request): OperationContext
    {
        return new OperationContext(new StubServer(), $request);
    }
}
