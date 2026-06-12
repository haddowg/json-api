<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship as InputToMany;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship as InputToOne;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\RelationshipType;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship as OutputToMany;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship as OutputToOne;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\FakeRelationshipLoadState;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\haddowg\JsonApi\Resource\Field\AbstractRelation::class)]
#[CoversClass(BelongsTo::class)]
#[CoversClass(HasOne::class)]
#[CoversClass(HasMany::class)]
#[CoversClass(BelongsToMany::class)]
#[CoversClass(MorphTo::class)]
#[CoversClass(MorphToMany::class)]
final class RelationTest extends TestCase
{
    #[Test]
    public function belongsToIsToOneAndBuildsToOneRelationship(): void
    {
        $relation = BelongsTo::make('author')->type('users');
        $model = ['author' => ['id' => '7', 'type' => 'users']];

        self::assertFalse($relation->isToMany());
        self::assertSame(['users'], $relation->relatedTypes());

        try {
            $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        } catch (\Throwable $e) {
            \fwrite(\STDERR, "TR\n" . $e->getTraceAsString() . "\n");
            throw $e;
        }
        self::assertInstanceOf(OutputToOne::class, $built);
    }

    #[Test]
    public function hasManyIsToManyAndBuildsToManyRelationship(): void
    {
        $relation = HasMany::make('comments')->type('comments');
        $model = ['comments' => [['id' => '1'], ['id' => '2']]];

        self::assertTrue($relation->isToMany());

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        self::assertInstanceOf(OutputToMany::class, $built);
    }

    #[Test]
    public function hasOneInheritsBelongsToBehaviour(): void
    {
        $relation = HasOne::make('profile')->type('profiles');

        self::assertFalse($relation->isToMany());
        self::assertInstanceOf(OutputToOne::class, $relation->buildRelationship(['profile' => null], $this->request(), $this->resolver()));
    }

    #[Test]
    public function relatedTypeAppendsRelationshipTypeConstraint(): void
    {
        $relation = BelongsTo::make('author')->type('users')->required();
        $constraintTypes = \array_map(static fn(object $c): string => $c::class, $relation->constraints());

        self::assertContains(RelationshipType::class, $constraintTypes);
        self::assertContains(\haddowg\JsonApi\Resource\Constraint\Required::class, $constraintTypes);
    }

    #[Test]
    public function hasManyItemConstraints(): void
    {
        $relation = HasMany::make('tags')->type('tags')->maxItems(5);
        $constraintTypes = \array_map(static fn(object $c): string => $c::class, $relation->constraints());

        self::assertContains(MaxItems::class, $constraintTypes);
    }

    #[Test]
    public function cannotEagerLoadTogglesFlag(): void
    {
        self::assertTrue(HasMany::make('a')->canEagerLoad());
        self::assertFalse(HasMany::make('a')->cannotEagerLoad()->canEagerLoad());
    }

    #[Test]
    public function uriFieldNameDefaultsToNameAndCanBeOverridden(): void
    {
        $relation = BelongsTo::make('author');
        self::assertSame('author', $relation->uriFieldName());

        $relation->withUriFieldName('writer');
        self::assertSame('writer', $relation->uriFieldName());
    }

    #[Test]
    public function includesLinksByDefaultAndWithoutLinksOptsOut(): void
    {
        $relation = BelongsTo::make('author')->type('users');
        self::assertTrue($relation->includesLinks());

        self::assertFalse(BelongsTo::make('author')->type('users')->withoutLinks()->includesLinks());
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function buildRelationshipStampsConventionLinksByDefault(): void
    {
        $relation = BelongsTo::make('author')->type('users')->withUriFieldName('writer');
        $model = ['author' => ['id' => '7', 'type' => 'users']];

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());

        $relationshipObject = $built->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                $model,
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            [
                'self' => 'https://api.example.com/articles/42/relationships/writer',
                'related' => 'https://api.example.com/articles/42/writer',
            ],
            $relationshipObject['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function buildRelationshipOmitsLinksWhenWithoutLinks(): void
    {
        $relation = HasMany::make('comments')->type('comments')->withoutLinks();
        $model = ['comments' => [['id' => '1', 'type' => 'comments']]];

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());

        $relationshipObject = $built->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                $model,
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertArrayNotHasKey('links', (array) $relationshipObject);
    }

    #[Test]
    public function hydrateToOneStoresRelatedId(): void
    {
        $relation = BelongsTo::make('author')->type('users')->storedAs('author_id');
        $model = ['author_id' => null];

        $input = new InputToOne(new ResourceIdentifier('users', '99'));
        $result = $relation->hydrateRelationship($model, $input);

        self::assertIsArray($result);
        self::assertSame('99', $result['author_id']);
    }

    #[Test]
    public function hydrateToManyStoresRelatedIds(): void
    {
        $relation = HasMany::make('tags')->type('tags')->storedAs('tag_ids');
        $model = ['tag_ids' => []];

        $input = new InputToMany([
            new ResourceIdentifier('tags', '1'),
            new ResourceIdentifier('tags', '2'),
        ]);
        $result = $relation->hydrateRelationship($model, $input);

        self::assertIsArray($result);
        self::assertSame(['1', '2'], $result['tag_ids']);
    }

    #[Test]
    public function mutabilityFlagsDefaultToAllowedAndOptOut(): void
    {
        $relation = HasMany::make('tags')->type('tags');
        self::assertTrue($relation->allowsReplace());
        self::assertTrue($relation->allowsRemove());

        $restricted = HasMany::make('tags')->type('tags')->cannotReplace()->cannotRemove();
        self::assertFalse($restricted->allowsReplace());
        self::assertFalse($restricted->allowsRemove());
    }

    #[Test]
    public function endpointExposureFlagsDefaultToExposedAndOptOut(): void
    {
        $relation = HasMany::make('tags')->type('tags');
        self::assertTrue($relation->exposesRelatedEndpoint());
        self::assertTrue($relation->exposesRelationshipEndpoint());
        self::assertTrue($relation->allowsAdd());

        $restricted = HasMany::make('tags')->type('tags')
            ->withoutRelatedEndpoint()
            ->withoutRelationshipEndpoint()
            ->cannotAdd();
        self::assertFalse($restricted->exposesRelatedEndpoint());
        self::assertFalse($restricted->exposesRelationshipEndpoint());
        self::assertFalse($restricted->allowsAdd());
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function buildRelationshipOmitsSelfLinkWhenRelationshipEndpointSuppressed(): void
    {
        $relation = BelongsTo::make('author')->type('users')->withoutRelationshipEndpoint();
        $model = ['author' => ['id' => '7', 'type' => 'users']];

        $links = $this->buildLinks($relation, $model);

        self::assertSame(
            ['related' => 'https://api.example.com/articles/42/author'],
            $links,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function buildRelationshipOmitsRelatedLinkWhenRelatedEndpointSuppressed(): void
    {
        $relation = HasMany::make('comments')->type('comments')->withoutRelatedEndpoint();
        $model = ['comments' => [['id' => '1', 'type' => 'comments']]];

        $links = $this->buildLinks($relation, $model);

        self::assertSame(
            ['self' => 'https://api.example.com/articles/42/relationships/comments'],
            $links,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function morphToOmitsRelatedLinkWhenRelatedEndpointSuppressed(): void
    {
        // Regression: a polymorphic MorphTo must thread its endpoint-exposure flags
        // into its convention links like every sibling relation, so suppressing the
        // related endpoint stops it advertising a link to a host the handler 404s.
        $relation = MorphTo::make('commentable')->types('posts', 'videos')->withoutRelatedEndpoint();
        $model = ['commentable' => ['kind' => 'videos', 'id' => '9']];

        $links = $this->buildLinks($relation, $model);

        self::assertSame(
            ['self' => 'https://api.example.com/articles/42/relationships/commentable'],
            $links,
        );
    }

    /**
     * Builds the relation's relationship object and returns its `links` member
     * (an empty array when none are emitted).
     *
     * @param array<string, mixed> $model
     *
     * @return array<string, mixed>
     */
    private function buildLinks(\haddowg\JsonApi\Resource\Field\AbstractRelation $relation, array $model): array
    {
        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());

        $relationshipObject = (array) $built->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                $model,
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        /** @var array<string, mixed> $links */
        $links = $relationshipObject['links'] ?? [];

        return $links;
    }

    #[Test]
    public function applyToManyReplaceSetsTheWholeColumn(): void
    {
        $relation = HasMany::make('tags')->type('tags')->storedAs('tag_ids');
        $input = new InputToMany([new ResourceIdentifier('tags', '5'), new ResourceIdentifier('tags', '6')]);

        $result = $relation->applyToMany(['tag_ids' => ['1', '2']], $input, \haddowg\JsonApi\Resource\Field\Mode::Replace);

        self::assertIsArray($result);
        self::assertSame(['5', '6'], $result['tag_ids']);
    }

    #[Test]
    public function applyToManyAddAppendsIdsIdempotently(): void
    {
        $relation = HasMany::make('tags')->type('tags')->storedAs('tag_ids');
        $input = new InputToMany([new ResourceIdentifier('tags', '2'), new ResourceIdentifier('tags', '3')]);

        $result = $relation->applyToMany(['tag_ids' => ['1', '2']], $input, \haddowg\JsonApi\Resource\Field\Mode::Add);

        self::assertIsArray($result);
        self::assertSame(['1', '2', '3'], $result['tag_ids']);
    }

    #[Test]
    public function applyToManyRemoveSubtractsIds(): void
    {
        $relation = HasMany::make('tags')->type('tags')->storedAs('tag_ids');
        $input = new InputToMany([new ResourceIdentifier('tags', '2')]);

        $result = $relation->applyToMany(['tag_ids' => ['1', '2', '3']], $input, \haddowg\JsonApi\Resource\Field\Mode::Remove);

        self::assertIsArray($result);
        self::assertSame(['1', '3'], $result['tag_ids']);
    }

    #[Test]
    public function hydrateRelationshipRespectsFillUsing(): void
    {
        $relation = BelongsTo::make('author')->fillUsing(
            static function (mixed $model, mixed $rel): array {
                self::assertIsArray($model);
                self::assertInstanceOf(InputToOne::class, $rel);
                $model['filled'] = $rel->resourceIdentifier?->id;

                return $model;
            },
        );

        $result = $relation->hydrateRelationship(['filled' => null], new InputToOne(new ResourceIdentifier('users', '5')));
        self::assertIsArray($result);
        self::assertSame('5', $result['filled']);
    }

    #[Test]
    public function belongsToManyDeclaresPivotFields(): void
    {
        $relation = BelongsToMany::make('roles')->type('roles')->fields(['assigned_at' => 'datetime']);

        self::assertTrue($relation->isToMany());
        self::assertSame(['assigned_at' => 'datetime'], $relation->pivotFields());
    }

    #[Test]
    public function belongsToManyResolvesClosurePivotFields(): void
    {
        $relation = BelongsToMany::make('roles')->fields(static fn(): array => ['x' => 1]);

        self::assertSame(['x' => 1], $relation->pivotFields());
    }

    #[Test]
    public function readValueReadsToOneRelatedValueWithoutSerializing(): void
    {
        $relation = BelongsTo::make('author')->type('users');
        $author = ['id' => '7', 'type' => 'users'];
        $model = ['author' => $author];

        self::assertSame($author, $relation->readValue($model, $this->request()));
    }

    #[Test]
    public function readValueReadsToManyRelatedValuesWithoutSerializing(): void
    {
        $relation = HasMany::make('comments')->type('comments');
        $comments = [['id' => '1'], ['id' => '2']];
        $model = ['comments' => $comments];

        self::assertSame($comments, $relation->readValue($model, $this->request()));
    }

    #[Test]
    public function readValueHonoursTheBackingColumn(): void
    {
        $relation = BelongsTo::make('author')->type('users')->storedAs('author_account');
        $account = ['id' => '99', 'type' => 'users'];
        $model = ['author_account' => $account];

        self::assertSame($account, $relation->readValue($model, $this->request()));
    }

    #[Test]
    public function readValueHonoursACustomExtractor(): void
    {
        $relation = BelongsTo::make('author')->type('users')->extractUsing(
            static fn(): array => ['id' => 'extracted', 'type' => 'users'],
        );

        self::assertSame(['id' => 'extracted', 'type' => 'users'], $relation->readValue(['author' => null], $this->request()));
    }

    #[Test]
    public function morphToDeclaresMultipleTypes(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');

        self::assertFalse($relation->isToMany());
        self::assertSame(['posts', 'videos'], $relation->relatedTypes());
    }

    #[Test]
    public function morphToResolvesSerializerByRelatedType(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');
        $model = ['commentable' => ['kind' => 'videos']];

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        self::assertInstanceOf(OutputToOne::class, $built);
    }

    #[Test]
    public function resolveSerializerReturnsTheSingleRegisteredSerializerForAMonomorphicRelation(): void
    {
        $relation = BelongsTo::make('author')->type('users');
        $resolver = $this->resolver();

        $serializer = $relation->resolveSerializer(['id' => '7'], $resolver);

        self::assertSame($resolver->serializerFor('users'), $serializer);
    }

    #[Test]
    public function resolveSerializerSelectsTheMatchingSerializerForAPolymorphicRelation(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');
        $resolver = $this->resolver();
        $related = ['kind' => 'videos', 'id' => '9'];

        $serializer = $relation->resolveSerializer($related, $resolver);

        self::assertNotNull($serializer);
        self::assertSame($resolver->serializerFor('videos'), $serializer);
        self::assertNotSame($resolver->serializerFor('posts'), $serializer);
        self::assertSame('videos', $serializer->getType($related));
    }

    #[Test]
    public function resolveSerializerReturnsTheFirstDeclaredSerializerForANullRelatedValue(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');
        $resolver = $this->resolver();

        $serializer = $relation->resolveSerializer(null, $resolver);

        self::assertSame($resolver->serializerFor('posts'), $serializer);
    }

    #[Test]
    public function resolveSerializerReturnsNullWhenNoDeclaredTypeMatchesThePolymorphicObject(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');

        $serializer = $relation->resolveSerializer(['kind' => 'tags'], $this->resolver());

        self::assertNull($serializer);
    }

    #[Test]
    public function morphToManyIsToManyAndDeclaresMultipleTypes(): void
    {
        $relation = MorphToMany::make('items')->types('posts', 'videos');

        self::assertTrue($relation->isToMany());
        self::assertSame(['posts', 'videos'], $relation->relatedTypes());
    }

    #[Test]
    public function morphToManyBuildsToManyRelationshipWithPerMemberTypes(): void
    {
        $relation = MorphToMany::make('items')->types('posts', 'videos');
        $model = ['items' => [['kind' => 'posts', 'id' => '1'], ['kind' => 'videos', 'id' => '2']]];

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        self::assertInstanceOf(OutputToMany::class, $built);

        $relationshipObject = (array) $built->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                $model,
                'articles',
                $this->request(),
                '',
                '',
                'items',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        // Each member's `type` came from its own object — proof that the bound
        // PolymorphicSerializer resolved a serializer per member.
        self::assertSame(
            [
                ['type' => 'posts', 'id' => '1'],
                ['type' => 'videos', 'id' => '2'],
            ],
            $relationshipObject['data'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:pagination')]
    public function paginationDefaultsToNullAndPaginateSetsIt(): void
    {
        $relation = HasMany::make('comments')->type('comments');
        self::assertNull($relation->pagination());

        $paginator = \haddowg\JsonApi\Pagination\PagePaginator::make();
        $returned = $relation->paginate($paginator);

        // paginate() mutates and returns the same builder (not a clone).
        self::assertSame($relation, $returned);
        self::assertSame($paginator, $relation->pagination());
    }

    #[Test]
    public function linkageOnlyWhenLoadedOffByDefaultAndOptsIn(): void
    {
        self::assertFalse(BelongsTo::make('author')->type('users')->emitsLinkageOnlyWhenLoaded());
        self::assertTrue(BelongsTo::make('author')->type('users')->linkageOnlyWhenLoaded()->emitsLinkageOnlyWhenLoaded());
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function linkageOnlyWhenLoadedOmitsDataWhenNotLoadedAndNotIncluded(): void
    {
        // (1) policy ON + predicate=false + not included + has links => data omitted, links present.
        $relation = BelongsTo::make('author')->type('users')->linkageOnlyWhenLoaded();
        $loadState = new FakeRelationshipLoadState(false);

        $relationshipObject = $this->buildAndTransform($relation, $loadState);

        self::assertArrayNotHasKey('data', $relationshipObject);
        self::assertArrayHasKey('links', $relationshipObject);
        self::assertNotSame([], $loadState->askedAbout, 'predicate must be consulted');
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function linkageOnlyWhenLoadedEmitsDataWhenLoaded(): void
    {
        // (2) policy ON + predicate=true => data present.
        $relation = BelongsTo::make('author')->type('users')->linkageOnlyWhenLoaded();

        $relationshipObject = $this->buildAndTransform($relation, new FakeRelationshipLoadState(true));

        self::assertSame(['type' => 'users', 'id' => '7'], $relationshipObject['data'] ?? null);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function linkageOnlyWhenLoadedEmitsDataWhenIncludedDespiteNotLoaded(): void
    {
        // (3) policy ON + included => data present (include-wins).
        $relation = BelongsTo::make('author')->type('users')->linkageOnlyWhenLoaded();

        $relationshipObject = $this->buildAndTransform(
            $relation,
            new FakeRelationshipLoadState(false),
            new StubJsonApiRequest(['include' => 'author']),
        );

        self::assertSame(['type' => 'users', 'id' => '7'], $relationshipObject['data'] ?? null);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function linkageOnlyWhenLoadedEmitsDataWithoutLinksDespiteNotLoaded(): void
    {
        // (4) policy ON + withoutLinks + predicate=false => data present (validity guard).
        $relation = BelongsTo::make('author')->type('users')->linkageOnlyWhenLoaded()->withoutLinks();

        $relationshipObject = $this->buildAndTransform($relation, new FakeRelationshipLoadState(false));

        self::assertSame(['type' => 'users', 'id' => '7'], $relationshipObject['data'] ?? null);
        self::assertArrayNotHasKey('links', $relationshipObject);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function policyOffEmitsDataRegardlessOfPredicate(): void
    {
        // (5) policy OFF => data always present regardless of predicate.
        $relation = BelongsTo::make('author')->type('users');

        $relationshipObject = $this->buildAndTransform($relation, new FakeRelationshipLoadState(false));

        self::assertSame(['type' => 'users', 'id' => '7'], $relationshipObject['data'] ?? null);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function anEmptyToOneBindsItsSerializerAndEmitsNullData(): void
    {
        // A null to-one (no related object) still binds its serializer, so the
        // relationship carries `data: null` rather than omitting the data member —
        // the precondition the relationship-linkage endpoint relies on to render
        // `data: null` per the spec.
        $relation = BelongsTo::make('author')->type('users');
        $model = ['author' => null];

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());

        $relationshipObject = (array) $built->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                $model,
                'articles',
                $this->request(),
                '',
                '',
                'author',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertArrayHasKey('data', $relationshipObject);
        self::assertNull($relationshipObject['data']);
    }

    /**
     * Runs the relation through build + transform with a load-state predicate
     * wired onto the resolver, returning the transformed relationship object.
     *
     * @return array<string, mixed>
     */
    private function buildAndTransform(
        BelongsTo $relation,
        FakeRelationshipLoadState $loadState,
        ?StubJsonApiRequest $request = null,
    ): array {
        $request ??= new StubJsonApiRequest();
        $model = ['author' => ['id' => '7', 'type' => 'users']];

        $resolver = (new StubSerializerResolver())->withRelationshipLoadState($loadState);

        $built = $relation->buildRelationship($model, $request, $resolver);

        $relationshipObject = $built->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                $model,
                'articles',
                $request,
                '',
                '',
                'author',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        return (array) $relationshipObject;
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }

    private function resolver(): \haddowg\JsonApi\Resource\SerializerResolverInterface
    {
        return new StubSerializerResolver();
    }
}
