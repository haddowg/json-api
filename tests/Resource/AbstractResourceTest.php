<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Hydrator\HydratorInterface as HydratorContract;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\Paginator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\SerializerResolver;
use haddowg\JsonApi\Resource\Sort\Sort;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractResource::class)]
final class AbstractResourceTest extends TestCase
{
    #[Test]
    public function satisfiesBothContracts(): void
    {
        $resource = new PostResource();

        self::assertInstanceOf(SerializerInterface::class, $resource);
        self::assertInstanceOf(HydratorContract::class, $resource);
    }

    #[Test]
    public function serializerSideReportsTypeAndId(): void
    {
        $resource = new PostResource();

        // getType() and getId() are request-independent: a resource's identity must
        // not vary by request, so neither receives the request.
        self::assertSame('posts', $resource->getType($this->post()));
        self::assertSame('7', $resource->getId($this->post()));
    }

    #[Test]
    public function attributeCallablesSerializeFields(): void
    {
        $resource = new PostResource();
        $request = new StubJsonApiRequest();

        $attributes = $resource->getAttributes($this->post(), $request);
        self::assertArrayHasKey('title', $attributes);
        self::assertArrayHasKey('viewCount', $attributes);
        self::assertArrayNotHasKey('id', $attributes);
        self::assertArrayNotHasKey('author', $attributes);

        self::assertSame('Hello', $attributes['title']($this->post(), $request, 'title'));
        self::assertSame(42, $attributes['viewCount']($this->post(), $request, 'viewCount'));
    }

    #[Test]
    public function hiddenFieldsAreNotSerialized(): void
    {
        $resource = new PostResource();
        $attributes = $resource->getAttributes($this->post(), new StubJsonApiRequest());

        self::assertArrayNotHasKey('secret', $attributes);
    }

    #[Test]
    public function relationshipsRequireAResolver(): void
    {
        $resource = new PostResource();
        $request = new StubJsonApiRequest();

        self::assertSame([], $resource->getRelationships($this->post(), $request));

        $resource->setSerializerResolver($this->resolver());
        $relationships = $resource->getRelationships($this->post(), $request);
        self::assertArrayHasKey('author', $relationships);
        self::assertArrayHasKey('comments', $relationships);
    }

    #[Test]
    public function allSortsDerivesFromSortableFieldsPlusExplicitSorts(): void
    {
        $resource = new PostResource();
        $keys = \array_map(static fn(Sort $sort): string => $sort->key(), $resource->allSorts());

        self::assertContains('title', $keys);
        self::assertContains('publishedAt', $keys);
        self::assertContains('relevance', $keys);
        self::assertNotContains('viewCount', $keys);
    }

    #[Test]
    public function filtersAndPaginationAreExposed(): void
    {
        $resource = new PostResource();

        self::assertCount(1, $resource->filters());
        self::assertSame('status', $resource->filters()[0]->key());
        self::assertInstanceOf(PagePaginator::class, $resource->pagination());
    }

    #[Test]
    public function hydratesCreateRequestIntoArrayModel(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'New title', 'published' => true],
            ],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame('New title', $model['title']);
        self::assertTrue($model['published']);
        self::assertArrayHasKey('id', $model);
        self::assertNotSame('', $model['id']);
    }

    #[Test]
    public function readOnlyFieldsAreNotHydratedOnCreate(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'T', 'viewCount' => 999],
            ],
        ]);

        $model = $resource->hydrate($request, ['viewCount' => 0]);

        self::assertIsArray($model);
        self::assertSame(0, $model['viewCount']);
    }

    #[Test]
    public function hydratesUpdateRequestWithoutChangingAbsentFields(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('PATCH', [
            'data' => [
                'type' => 'posts',
                'id' => '7',
                'attributes' => ['title' => 'Patched'],
            ],
        ]);

        $model = $resource->hydrate($request, ['title' => 'Old', 'published' => true]);

        self::assertIsArray($model);
        self::assertSame('Patched', $model['title']);
        self::assertTrue($model['published']);
    }

    #[Test]
    public function rejectsMismatchedType(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'comments', 'attributes' => []],
        ]);

        $this->expectException(\haddowg\JsonApi\Exception\ResourceTypeUnacceptable::class);
        $resource->hydrate($request, []);
    }

    #[Test]
    public function rejectsClientGeneratedIdByDefault(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'posts', 'id' => 'client-id', 'attributes' => []],
        ]);

        $this->expectException(\haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported::class);
        $resource->hydrate($request, []);
    }

    #[Test]
    public function hydratesToOneRelationship(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'T'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'users', 'id' => '99']],
                ],
            ],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame('99', $model['author']);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(): array
    {
        return [
            'id' => 7,
            'title' => 'Hello',
            'viewCount' => 42,
            'published' => true,
            'secret' => 'do not show',
            'author' => ['id' => '1', 'type' => 'users'],
            'comments' => [],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRequest(string $method, array $body): JsonApiRequest
    {
        $psr = (new ServerRequest($method, '/api/posts'))
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/vnd.api+json');

        return new JsonApiRequest($psr);
    }

    private function resolver(): SerializerResolver
    {
        return new StubSerializerResolver();
    }
}

/**
 * Sample schema used across the integration tests.
 */
final class PostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Integer::make('viewCount')->readOnly()->min(0),
            Boolean::make('published'),
            DateTime::make('publishedAt')->sortable(),
            Str::make('secret')->hidden(),
            BelongsTo::make('author')->type('users'),
            HasMany::make('comments')->type('comments'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('status'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('relevance', 'score'),
        ];
    }

    public function pagination(): Paginator
    {
        return PagePaginator::make()->withDefaultPerPage(15);
    }
}
