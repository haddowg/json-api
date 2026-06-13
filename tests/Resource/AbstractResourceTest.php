<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Hydrator\HydratorInterface as HydratorContract;
use haddowg\JsonApi\Pagination\PagePaginator;
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
    public function uriTypeDefaultsToTheJsonApiType(): void
    {
        self::assertSame('posts', (new PostResource())->uriType());
    }

    #[Test]
    public function uriTypeUsesTheDeclaredSegmentWhenSet(): void
    {
        // The JSON:API type stays singular; only the URI segment differs.
        self::assertSame('segment', SegmentedResource::$type);
        self::assertSame('segments', (new SegmentedResource())->uriType());
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
        $keys = \array_map(static fn(\haddowg\JsonApi\Resource\Sort\SortInterface $sort): string => $sort->key(), $resource->allSorts());

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

    #[Test]
    public function relationNamedResolvesADeclaredRelation(): void
    {
        $resource = new PostResource();

        $author = $resource->relationNamed('author');
        self::assertNotNull($author);
        self::assertFalse($author->isToMany());
        self::assertSame(['users'], $author->relatedTypes());

        $comments = $resource->relationNamed('comments');
        self::assertNotNull($comments);
        self::assertTrue($comments->isToMany());
        self::assertSame(['comments'], $comments->relatedTypes());
    }

    #[Test]
    public function relationNamedReturnsNullForAnUnknownOrNonRelationField(): void
    {
        $resource = new PostResource();

        // No such relation.
        self::assertNull($resource->relationNamed('publisher'));
        // An attribute field is not a relation.
        self::assertNull($resource->relationNamed('title'));
    }

    #[Test]
    public function relationNamedReadsTheRelatedValueOffTheParent(): void
    {
        $resource = new PostResource();
        $relation = $resource->relationNamed('author');
        self::assertNotNull($relation);

        self::assertSame(
            ['id' => '1', 'type' => 'users'],
            $relation->readValue($this->post(), new StubJsonApiRequest()),
        );
    }

    #[Test]
    public function patchRelationshipEndpointReplacesAToOne(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('PATCH', ['data' => ['type' => 'users', 'id' => '42']]);

        $model = $resource->hydrateRelationship('author', $request, ['author' => '1']);

        self::assertIsArray($model);
        self::assertSame('42', $model['author']);
    }

    #[Test]
    public function patchRelationshipEndpointClearsAToOneWithNullData(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('PATCH', ['data' => null]);

        $model = $resource->hydrateRelationship('author', $request, ['author' => '1']);

        self::assertIsArray($model);
        self::assertNull($model['author']);
    }

    #[Test]
    public function patchRelationshipEndpointReplacesAToMany(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('PATCH', [
            'data' => [
                ['type' => 'comments', 'id' => '5'],
                ['type' => 'comments', 'id' => '6'],
            ],
        ]);

        $model = $resource->hydrateRelationship('comments', $request, ['comments' => ['1', '2']]);

        self::assertIsArray($model);
        self::assertSame(['5', '6'], $model['comments']);
    }

    #[Test]
    public function postRelationshipEndpointAddsToAToMany(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => [
                ['type' => 'comments', 'id' => '2'],
                ['type' => 'comments', 'id' => '3'],
            ],
        ]);

        // Add is idempotent (set semantics): the already-present '2' is not duplicated.
        $model = $resource->hydrateRelationship('comments', $request, ['comments' => ['1', '2']]);

        self::assertIsArray($model);
        self::assertSame(['1', '2', '3'], $model['comments']);
    }

    #[Test]
    public function deleteRelationshipEndpointRemovesFromAToMany(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('DELETE', [
            'data' => [
                ['type' => 'comments', 'id' => '2'],
            ],
        ]);

        $model = $resource->hydrateRelationship('comments', $request, ['comments' => ['1', '2', '3']]);

        self::assertIsArray($model);
        self::assertSame(['1', '3'], $model['comments']);
    }

    #[Test]
    public function hydrateRelationshipRejectsAnUnknownRelationship(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('PATCH', ['data' => null]);

        $this->expectException(\haddowg\JsonApi\Exception\RelationshipNotExists::class);
        $resource->hydrateRelationship('publisher', $request, []);
    }

    #[Test]
    public function postToARelationshipEndpointOfAToOneIsACardinalityError(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', ['data' => [['type' => 'users', 'id' => '9']]]);

        $this->expectException(\haddowg\JsonApi\Exception\RelationshipTypeInappropriate::class);
        $resource->hydrateRelationship('author', $request, ['author' => '1']);
    }

    #[Test]
    public function deleteToARelationshipEndpointOfAToOneIsACardinalityError(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('DELETE', ['data' => [['type' => 'users', 'id' => '9']]]);

        $this->expectException(\haddowg\JsonApi\Exception\RelationshipTypeInappropriate::class);
        $resource->hydrateRelationship('author', $request, ['author' => '1']);
    }

    #[Test]
    public function patchReplaceIsRejectedWhenTheRelationCannotReplace(): void
    {
        $resource = new RestrictedResource();
        $request = $this->createRequest('PATCH', [
            'data' => [['type' => 'tags', 'id' => '5']],
        ]);

        $this->expectException(\haddowg\JsonApi\Exception\FullReplacementProhibited::class);
        $resource->hydrateRelationship('tags', $request, ['tags' => ['1']]);
    }

    #[Test]
    public function postAddIsRejectedWhenTheRelationCannotAdd(): void
    {
        $resource = new RestrictedResource();
        $request = $this->createRequest('POST', [
            'data' => [['type' => 'tags', 'id' => '5']],
        ]);

        $this->expectException(\haddowg\JsonApi\Exception\AdditionProhibited::class);
        $resource->hydrateRelationship('pinned', $request, ['pinned' => ['1']]);
    }

    #[Test]
    public function deleteRemoveIsRejectedWhenTheRelationCannotRemove(): void
    {
        $resource = new RestrictedResource();
        $request = $this->createRequest('DELETE', [
            'data' => [['type' => 'tags', 'id' => '1']],
        ]);

        $this->expectException(\haddowg\JsonApi\Exception\RemovalProhibited::class);
        $resource->hydrateRelationship('tags', $request, ['tags' => ['1']]);
    }

    #[Test]
    public function patchClearOfAToOneIsRejectedWhenTheRelationCannotRemove(): void
    {
        // A to-one PATCH `{data:null}` is a *removal*, gated by allowsRemove().
        $resource = new RestrictedResource();
        $request = $this->createRequest('PATCH', ['data' => null]);

        $this->expectException(\haddowg\JsonApi\Exception\RemovalProhibited::class);
        $resource->hydrateRelationship('owner', $request, ['owner' => '1']);
    }

    #[Test]
    public function patchReplaceOfAToOneIsRejectedWhenTheRelationCannotReplace(): void
    {
        $resource = new RestrictedResource();
        $request = $this->createRequest('PATCH', ['data' => ['type' => 'users', 'id' => '9']]);

        $this->expectException(\haddowg\JsonApi\Exception\FullReplacementProhibited::class);
        $resource->hydrateRelationship('owner', $request, ['owner' => '1']);
    }

    #[Test]
    public function nonIncludableRelationshipsAreDerivedFromCannotBeIncluded(): void
    {
        $resource = new IncludeControlledResource();

        // 'secret' opted out via cannotBeIncluded(); 'author' did not.
        self::assertSame(['secret'], $resource->getNonIncludableRelationships([]));
    }

    #[Test]
    public function includeControlsDefaultToUnrestricted(): void
    {
        $resource = new PostResource();

        // No per-resource depth override and no allowed-paths whitelist by default,
        // so every AbstractResource subclass is unrestricted without any edit.
        self::assertNull($resource->maxIncludeDepth());
        self::assertNull($resource->getAllowedIncludePaths());
        self::assertSame([], $resource->getNonIncludableRelationships([]));
    }

    #[Test]
    public function includeControlsExposeTheAuthorOverrides(): void
    {
        $resource = new IncludeControlledResource();

        self::assertSame(2, $resource->maxIncludeDepth());
        self::assertSame(['author'], $resource->getAllowedIncludePaths());
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

    private function resolver(): \haddowg\JsonApi\Resource\SerializerResolverInterface
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

    public function pagination(): \haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return PagePaginator::make()->withDefaultPerPage(15);
    }
}

/**
 * A resource whose URI segment (`segments`) differs from its JSON:API type
 * (`segment`), exercising the {@see AbstractResource::uriType()} override.
 */
final class SegmentedResource extends AbstractResource
{
    public static string $type = 'segment';

    public static string $uriType = 'segments';

    public function fields(): array
    {
        return [
            Id::make(),
        ];
    }
}

/**
 * A resource whose relationships opt out of replace / add / remove, exercising
 * the mutability gates ({@see \haddowg\JsonApi\Exception\FullReplacementProhibited} /
 * {@see \haddowg\JsonApi\Exception\AdditionProhibited} /
 * {@see \haddowg\JsonApi\Exception\RemovalProhibited}).
 */
final class RestrictedResource extends AbstractResource
{
    public static string $type = 'restricted';

    public function fields(): array
    {
        return [
            Id::make(),
            // A to-many that may be added to but never replaced or removed from.
            HasMany::make('tags')->type('tags')->cannotReplace()->cannotRemove(),
            // A to-many that may be replaced / removed from but never added to.
            HasMany::make('pinned')->type('tags')->cannotAdd(),
            // A to-one that may be replaced but never cleared.
            BelongsTo::make('owner')->type('users')->cannotReplace()->cannotRemove(),
        ];
    }
}

/**
 * A resource exercising the include safeguards: a relation that opts out of
 * inclusion ({@see \haddowg\JsonApi\Resource\Field\AbstractRelation::cannotBeIncluded()}),
 * a per-resource maximum include depth and an allowed-include-paths whitelist
 * ({@see \haddowg\JsonApi\Serializer\IncludeControlsInterface}).
 */
final class IncludeControlledResource extends AbstractResource
{
    public static string $type = 'controlled';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('author')->type('users'),
            BelongsTo::make('secret')->type('secrets')->cannotBeIncluded(),
        ];
    }

    public function maxIncludeDepth(): int
    {
        return 2;
    }

    /**
     * @return list<string>
     */
    public function getAllowedIncludePaths(): array
    {
        return ['author'];
    }
}
