<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Hydrator\HydratorInterface as HydratorContract;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
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
use haddowg\JsonApi\Tests\Double\RejectingIdEncoder;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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
    #[Group('spec:sparse-fieldsets')]
    public function declaredFieldNamesExposesTheFullUnfilteredMemberNamespace(): void
    {
        // Every declared field name, request-independent and UNFILTERED by
        // visibility: attributes (incl. the hidden `secret` and write-only
        // `password`), relationships, and `id`.
        self::assertSame(
            ['id', 'title', 'viewCount', 'published', 'publishedAt', 'secret', 'password', 'author', 'comments'],
            (new PostResource())->declaredFieldNames(),
        );
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
    public function writeOnlyFieldsAreNotSerialized(): void
    {
        // A write-only field never appears in the rendered attributes — skipped
        // alongside sparse-fieldset filtering, so it is absent from every read.
        $resource = new PostResource();
        $attributes = $resource->getAttributes($this->post(), new StubJsonApiRequest());

        self::assertArrayNotHasKey('password', $attributes);
        // The other attributes are unaffected.
        self::assertArrayHasKey('title', $attributes);
    }

    #[Test]
    public function sparseFieldsetCannotResurrectAWriteOnlyField(): void
    {
        // Even a fields[posts] explicitly naming the write-only member does not
        // bring it back: it is dropped before sparse-fieldset filtering runs.
        $resource = new PostResource();
        $request = StubJsonApiRequest::create(['fields' => ['posts' => 'title,password']]);

        $attributes = $resource->getAttributes($this->post(), $request);

        self::assertArrayNotHasKey('password', $attributes);
    }

    #[Test]
    public function writeOnlyFieldsAreHydratedOnCreate(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('POST', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'T', 'password' => 'hunter2!'],
            ],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame('hunter2!', $model['password'], 'a write-only field is accepted on create');
    }

    #[Test]
    public function writeOnlyFieldsAreHydratedOnUpdate(): void
    {
        $resource = new PostResource();
        $request = $this->createRequest('PATCH', [
            'data' => [
                'type' => 'posts',
                'id' => '7',
                'attributes' => ['password' => 'rotated!'],
            ],
        ]);

        $model = $resource->hydrate($request, ['password' => 'original']);

        self::assertIsArray($model);
        self::assertSame('rotated!', $model['password'], 'a write-only field is accepted on update');
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
        self::assertInstanceOf(PagePaginator::class, $resource->pagination(null));
    }

    #[Test]
    public function paginationInheritsTheServerDefaultByDefault(): void
    {
        $resource = new SegmentedResource();
        $serverDefault = PagePaginator::make()->withDefaultPerPage(7);

        // The base implementation returns the resolved server-default argument
        // verbatim, so a non-overriding resource inherits it.
        self::assertSame($serverDefault, $resource->pagination($serverDefault));
        self::assertNull($resource->pagination(null));
    }

    #[Test]
    public function paginationOverrideIsUsedVerbatim(): void
    {
        $resource = new PostResource();

        // The override pins its own strategy regardless of the passed default.
        self::assertInstanceOf(
            PagePaginator::class,
            $resource->pagination(OffsetPaginator::make()),
        );
    }

    #[Test]
    public function resourceIsNotCountableByDefaultThenOptsIn(): void
    {
        $resource = new SegmentedResource();

        self::assertFalse($resource->isCountable());
        self::assertSame($resource, $resource->countable());
        self::assertTrue($resource->isCountable());
    }

    #[Test]
    public function aRelationNamedSelfTokenIsRejectedAtBuild(): void
    {
        // `_self_` is the reserved ?withCount token for the primary collection, so a
        // relation literally named `_self_` is ambiguous and rejected when the field
        // inventory is first indexed.
        $resource = new SelfTokenRelationResource();

        $this->expectException(\LogicException::class);

        $resource->relationNamed('_self_');
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
        // A plain Id::make() is store-provided by default: core sets no id, leaving
        // it for the persister/DB to assign.
        self::assertArrayNotHasKey('id', $model);
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
    public function decodesAClientGeneratedIdToTheStorageKeyOnCreate(): void
    {
        $resource = new EncodedIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'encoded', 'id' => '12345', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        // The wire id '12345' decodes (reverses) to the storage key '54321'.
        self::assertSame('54321', $model['id']);
    }

    #[Test]
    public function rejectsAnUndecodableClientGeneratedIdWith422(): void
    {
        $resource = new RejectingIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'rejected', 'id' => 'well-formed-but-unknown', 'attributes' => []],
        ]);

        try {
            $resource->hydrate($request, []);
            self::fail('Expected ResourceIdUndecodable.');
        } catch (\haddowg\JsonApi\Exception\ResourceIdUndecodable $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('well-formed-but-unknown', $exception->id);
        }
    }

    #[Test]
    public function doesNotDecodeAServerGeneratedIdOnCreate(): void
    {
        // The encoder rejects every wire id, but a create with no client id must use
        // the server-generated value as-is rather than feeding it to decode() — a
        // server-minted id is the storage key's own wire form, not the encoder's
        // input, so decoding it would 422 every server-generated create.
        $resource = new ServerGeneratedEncodedIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'server-encoded', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame(ServerGeneratedEncodedIdResource::GENERATED_ID, $model['id']);
    }

    #[Test]
    public function storeProvidedIsTheDefaultAndSetsNoIdOnCreate(): void
    {
        // The default fallback: no client id and no generated() / generateUsing(),
        // so core sets nothing — the persister/DB assigns the id.
        $resource = new StoreProvidedIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'store-provided', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertArrayNotHasKey('id', $model);
    }

    #[Test]
    public function anOptionalClientIdIsUsedWhenSupplied(): void
    {
        $resource = new OptionalClientIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'optional', 'id' => 'client-chosen', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame('client-chosen', $model['id']);
    }

    #[Test]
    public function anOptionalClientIdFallsBackToStoreProvidedWhenAbsent(): void
    {
        $resource = new OptionalClientIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'optional', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertArrayNotHasKey('id', $model);
    }

    #[Test]
    public function aRequiredClientIdIsUsedWhenSupplied(): void
    {
        $resource = new RequiredClientIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'required', 'id' => 'mandatory', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame('mandatory', $model['id']);
    }

    #[Test]
    public function aRequiredClientIdIsRejectedWith403WhenAbsent(): void
    {
        $resource = new RequiredClientIdResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'required', 'attributes' => []],
        ]);

        try {
            $resource->hydrate($request, []);
            self::fail('Expected ClientGeneratedIdRequired.');
        } catch (\haddowg\JsonApi\Exception\ClientGeneratedIdRequired $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    #[Test]
    public function generatedUuidMintsAUuidWhenNoClientIdIsSupplied(): void
    {
        $resource = new GeneratedUuidResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'gen-uuid', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertIsString($model['id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $model['id'],
        );
    }

    #[Test]
    public function generatedUlidMintsAUlidWhenNoClientIdIsSupplied(): void
    {
        $resource = new GeneratedUlidResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'gen-ulid', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertIsString($model['id']);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $model['id']);
    }

    #[Test]
    public function generateUsingMintsTheClosureValueAsTheStorageKey(): void
    {
        $resource = new GeneratedClosureResource();
        $request = $this->createRequest('POST', [
            'data' => ['type' => 'gen-closure', 'attributes' => []],
        ]);

        $model = $resource->hydrate($request, []);

        self::assertIsArray($model);
        self::assertSame(GeneratedClosureResource::GENERATED_ID, $model['id']);
    }

    #[Test]
    public function generatedOnANonSelfGeneratingFormatIsAConfigError(): void
    {
        $this->expectException(\LogicException::class);

        Id::make()->numeric()->generated();
    }

    #[Test]
    public function generatedWithoutAnyFormatIsAConfigError(): void
    {
        $this->expectException(\LogicException::class);

        Id::make()->generated();
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
    public function onAttributeFlattensTheRelatedModelOnRead(): void
    {
        $resource = new FlattenedResource();
        $request = new StubJsonApiRequest();

        $author = (object) ['name' => 'Ada', 'displayName' => 'Ada L.'];
        $book = ['id' => '1', 'title' => 'T', 'author' => $author];

        $attributes = $resource->getAttributes($book, $request);

        self::assertArrayHasKey('authorName', $attributes);
        self::assertArrayHasKey('authorDisplay', $attributes);
        // authorName reads the related model's `name` (via storedAs); authorDisplay
        // reads its `displayName` (the default column == name).
        self::assertSame('Ada', $attributes['authorName']($book, $request, 'authorName'));
        self::assertSame('Ada L.', $attributes['authorDisplay']($book, $request, 'authorDisplay'));
    }

    #[Test]
    public function onAttributeIsNullWhenTheRelatedModelIsAbsentOnRead(): void
    {
        $resource = new FlattenedResource();
        $request = new StubJsonApiRequest();

        $book = ['id' => '1', 'title' => 'T', 'author' => null];
        $attributes = $resource->getAttributes($book, $request);

        self::assertNull($attributes['authorName']($book, $request, 'authorName'));
    }

    #[Test]
    public function onAttributeDoesNotRenderItsBackingRelationshipWhenHidden(): void
    {
        // The backing `author` relation is hidden(), so it is not a rendered
        // relationship even though the flattened attribute is rendered.
        $resource = new FlattenedResource();
        $resource->setSerializerResolver($this->resolver());
        $request = new StubJsonApiRequest();

        $book = ['id' => '1', 'title' => 'T', 'author' => (object) ['name' => 'Ada', 'displayName' => 'Ada L.']];

        self::assertArrayNotHasKey('author', $resource->getRelationships($book, $request));
        self::assertArrayHasKey('authorName', $resource->getAttributes($book, $request));
    }

    #[Test]
    public function onAttributeWritesOntoTheExistingRelatedModelOnUpdate(): void
    {
        $resource = new FlattenedResource();
        $author = (object) ['name' => 'Ada', 'displayName' => 'Ada L.'];
        $book = (object) ['id' => '1', 'title' => 'T', 'author' => $author];

        $request = $this->createRequest('PATCH', [
            'data' => [
                'type' => 'books',
                'id' => '1',
                'attributes' => ['authorName' => 'Grace'],
            ],
        ]);

        $model = $resource->hydrate($request, $book);

        // The parent's association is unchanged; the related model is mutated in place.
        self::assertSame($book, $model);
        self::assertSame('Grace', $author->name);
    }

    #[Test]
    public function onAttributeWritesAgainstARelationAssociatedInTheSameCreateBody(): void
    {
        // The `author` relation is associated in this same POST body; the flattened
        // `authorName` must hydrate AFTER relationships so it sees the new related
        // model. Here the relation's fillUsing materialises the related object.
        $resource = new FlattenedWithSettableRelationResource();
        $request = $this->createRequest('POST', [
            'data' => [
                'type' => 'books',
                'attributes' => ['title' => 'T', 'authorName' => 'Grace'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'authors', 'id' => '9']],
                ],
            ],
        ]);

        $book = (object) ['title' => null, 'author' => null];
        $model = $resource->hydrate($request, $book);

        self::assertSame($book, $model);
        self::assertIsObject($book->author);
        self::assertSame('9', $book->author->id);
        // The flattened write landed on the freshly associated related model.
        self::assertSame('Grace', $book->author->name);
    }

    #[Test]
    public function onAttributeWriteWith422WhenTheRelatedModelIsAbsent(): void
    {
        $resource = new FlattenedResource();
        $book = (object) ['id' => '1', 'title' => 'T', 'author' => null];

        $request = $this->createRequest('PATCH', [
            'data' => [
                'type' => 'books',
                'id' => '1',
                'attributes' => ['authorName' => 'Grace'],
            ],
        ]);

        try {
            $resource->hydrate($request, $book);
            self::fail('Expected RelatedAttributeOwnerMissing.');
        } catch (\haddowg\JsonApi\Exception\RelatedAttributeOwnerMissing $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('authorName', $exception->attribute);
            self::assertSame('author', $exception->relation);
            self::assertSame(
                '/data/attributes/authorName',
                $exception->getErrors()[0]->source?->pointer,
            );
        }
    }

    #[Test]
    public function onAttributeAgainstAToManyRelationIsAnAuthorError(): void
    {
        $resource = new FlattenedToManyResource();
        $request = $this->createRequest('PATCH', [
            'data' => ['type' => 'books', 'id' => '1', 'attributes' => ['tagName' => 'x']],
        ]);

        $this->expectException(\LogicException::class);
        $resource->hydrate($request, (object) ['tags' => []]);
    }

    #[Test]
    public function eagerLoadRelationshipPathsIsTheDedupSetOfEveryOnPath(): void
    {
        // The dedup set of every on() chain in field order — single-hop `author`
        // (shared by authorName + authorDisplay, deduped) then multi-hop
        // `publisher.country`. No alwaysLoadRelationships() arm exists.
        self::assertSame(
            ['author', 'publisher.country'],
            (new MultiHopFlattenedResource())->eagerLoadRelationshipPaths(),
        );
    }

    #[Test]
    public function eagerLoadRelationshipPathsIsEmptyForAResourceWithoutOn(): void
    {
        self::assertSame([], (new PostResource())->eagerLoadRelationshipPaths());
    }

    #[Test]
    public function onAttributeFlattensAMultiHopToOneChainOnRead(): void
    {
        // publisher.country: walk model -> publisher -> country, read the country's
        // `name`. The chain walk past hop 0 needs the serializer resolver to find the
        // intermediate `publishers` type's relation inventory.
        $resource = new MultiHopFlattenedResource();
        $resource->setSerializerResolver($this->chainResolver());
        $request = new StubJsonApiRequest();

        $country = (object) ['name' => 'France'];
        $publisher = (object) ['country' => $country];
        $book = ['id' => '1', 'title' => 'T', 'author' => null, 'publisher' => $publisher];

        $attributes = $resource->getAttributes($book, $request);
        self::assertArrayHasKey('countryName', $attributes);
        self::assertSame('France', $attributes['countryName']($book, $request, 'countryName'));
    }

    #[Test]
    public function onAttributeShortCircuitsToNullOnAnIntermediateNullHop(): void
    {
        // publisher is null: the chain short-circuits to null without touching country.
        $resource = new MultiHopFlattenedResource();
        $resource->setSerializerResolver($this->chainResolver());
        $request = new StubJsonApiRequest();

        $book = ['id' => '1', 'title' => 'T', 'author' => null, 'publisher' => null];

        $attributes = $resource->getAttributes($book, $request);
        self::assertNull($attributes['countryName']($book, $request, 'countryName'));
    }

    #[Test]
    public function onAttributeHonoursAStoredAsIntermediateRelationOnRead(): void
    {
        // The `publisher` relation is storedAs('imprint'): the chain reads the
        // intermediate hop from the `imprint` member, then the country's `name`.
        $resource = new StoredAsHopFlattenedResource();
        $resource->setSerializerResolver($this->chainResolver());
        $request = new StubJsonApiRequest();

        $country = (object) ['name' => 'Spain'];
        $book = ['id' => '1', 'imprint' => (object) ['country' => $country]];

        $attributes = $resource->getAttributes($book, $request);
        self::assertSame('Spain', $attributes['countryName']($book, $request, 'countryName'));
    }

    #[Test]
    public function onAttributeWritesOntoTheFinalRelatedModelOfAMultiHopChainOnUpdate(): void
    {
        // publisher.country: a PATCH of `countryName` writes onto the final country.
        $resource = new MultiHopFlattenedResource();
        $resource->setSerializerResolver($this->chainResolver());

        $country = (object) ['name' => 'France'];
        $book = (object) ['id' => '1', 'author' => null, 'publisher' => (object) ['country' => $country]];

        $request = $this->createRequest('PATCH', [
            'data' => [
                'type' => 'books',
                'id' => '1',
                'attributes' => ['countryName' => 'Germany'],
            ],
        ]);

        $model = $resource->hydrate($request, $book);

        // The parent's association is unchanged; the final related model is mutated.
        self::assertSame($book, $model);
        self::assertSame('Germany', $country->name);
    }

    #[Test]
    public function onAttributeWriteWith422WhenAnIntermediateHopIsNull(): void
    {
        // publisher is null: the chain has no owner for the final write -> 422.
        $resource = new MultiHopFlattenedResource();
        $resource->setSerializerResolver($this->chainResolver());

        $book = (object) ['id' => '1', 'author' => null, 'publisher' => null];

        $request = $this->createRequest('PATCH', [
            'data' => [
                'type' => 'books',
                'id' => '1',
                'attributes' => ['countryName' => 'Germany'],
            ],
        ]);

        try {
            $resource->hydrate($request, $book);
            self::fail('Expected RelatedAttributeOwnerMissing.');
        } catch (\haddowg\JsonApi\Exception\RelatedAttributeOwnerMissing $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('countryName', $exception->attribute);
            self::assertSame('publisher.country', $exception->relation);
            self::assertSame(
                '/data/attributes/countryName',
                $exception->getErrors()[0]->source?->pointer,
            );
        }
    }

    #[Test]
    public function nonIncludableRelationshipsAreDerivedFromCannotBeIncluded(): void
    {
        $resource = new IncludeControlledResource();

        // 'secret' opted out via cannotBeIncluded(); 'author' did not.
        self::assertSame(['secret'], $resource->getNonIncludableRelationships(new StubJsonApiRequest(), []));
    }

    #[Test]
    public function includeControlsDefaultToUnrestricted(): void
    {
        $resource = new PostResource();

        // No per-resource depth override and no allowed-paths whitelist by default,
        // so every AbstractResource subclass is unrestricted without any edit.
        self::assertNull($resource->maxIncludeDepth());
        self::assertNull($resource->getAllowedIncludePaths());
        self::assertSame([], $resource->getNonIncludableRelationships(new StubJsonApiRequest(), []));
    }

    #[Test]
    public function includeControlsExposeTheAuthorOverrides(): void
    {
        $resource = new IncludeControlledResource();

        self::assertSame(2, $resource->maxIncludeDepth());
        self::assertSame(['author'], $resource->getAllowedIncludePaths());
    }

    // ---- request-aware predicates wired into AbstractResource ---------------

    #[Test]
    public function aRequestAwareHiddenAttributeIsRenderedForAdminAndSkippedOtherwise(): void
    {
        $resource = new RoleAwareResource();
        $model = ['id' => '1', 'title' => 'T', 'secret' => 's', 'token' => 'x', 'locked' => 'L'];

        $forAdmin = $resource->getAttributes($model, $this->roleRequest('admin'));
        self::assertArrayHasKey('secret', $forAdmin, 'admin sees the conditionally-hidden attribute');

        $forGuest = $resource->getAttributes($model, $this->roleRequest(null));
        self::assertArrayNotHasKey('secret', $forGuest, 'a non-admin does not');
    }

    #[Test]
    public function aRequestAwareWriteOnlyAttributeIsNeverRenderedForANonAdmin(): void
    {
        $resource = new RoleAwareResource();
        $model = ['id' => '1', 'title' => 'T', 'token' => 'x'];

        $forGuest = $resource->getAttributes($model, $this->roleRequest(null));
        self::assertArrayNotHasKey('token', $forGuest, 'write-only-for-guest is not echoed');

        $forAdmin = $resource->getAttributes($model, $this->roleRequest('admin'));
        self::assertArrayHasKey('token', $forAdmin, 'an admin (not write-only) sees it');
    }

    #[Test]
    public function aRequestAwareReadOnlyOnUpdateAttributeIsIgnoredForANonAdmin(): void
    {
        $resource = new RoleAwareResource();

        // A non-admin PATCH of `locked` is silently dropped (read-only for them).
        $guestModel = $resource->hydrate(
            $this->createRequest('PATCH', [
                'data' => ['type' => 'role_aware', 'id' => '1', 'attributes' => ['locked' => 'changed']],
            ]),
            ['locked' => 'original'],
        );
        self::assertIsArray($guestModel);
        self::assertSame('original', $guestModel['locked']);

        // The same PATCH from an admin lands.
        $adminModel = $resource->hydrate(
            $this->createRequest('PATCH', [
                'data' => ['type' => 'role_aware', 'id' => '1', 'attributes' => ['locked' => 'changed']],
            ], ['X-Role' => 'admin']),
            ['locked' => 'original'],
        );
        self::assertIsArray($adminModel);
        self::assertSame('changed', $adminModel['locked']);
    }

    #[Test]
    public function aRequestAwareCannotReplaceRelationshipIs403ForANonAdminOnly(): void
    {
        $resource = new RoleAwareResource();

        // A non-admin PATCH replacing `owner` is prohibited.
        $guest = $this->createRequest('PATCH', ['data' => ['type' => 'users', 'id' => '9']]);
        try {
            $resource->hydrateRelationship('owner', $guest, ['owner' => '1']);
            self::fail('expected FullReplacementProhibited for a non-admin');
        } catch (\haddowg\JsonApi\Exception\FullReplacementProhibited) {
            $this->addToAssertionCount(1);
        }

        // An admin's identical replacement succeeds (no throw).
        $admin = $this->createRequest('PATCH', ['data' => ['type' => 'users', 'id' => '9']], ['X-Role' => 'admin']);
        $model = $resource->hydrateRelationship('owner', $admin, ['owner' => '1']);
        self::assertIsArray($model);
        self::assertSame('9', $model['owner']);
    }

    #[Test]
    public function aRequestAwareNonIncludableRelationIsReportedPerRequest(): void
    {
        $resource = new RoleAwareResource();

        // For a non-admin, `audit` is non-includable; for an admin it is includable.
        self::assertSame(['audit'], $resource->getNonIncludableRelationships($this->roleRequest(null), []));
        self::assertSame([], $resource->getNonIncludableRelationships($this->roleRequest('admin'), []));
    }

    #[Test]
    public function aConditionallyHiddenRelationStillResolvesForTheMutationLookup(): void
    {
        // A relation gated only by a request predicate must still resolve via
        // relationNamed() so a cannotReplaceFor 403 never degrades to a 404. The
        // RoleAwareResource's `owner` carries a cannotReplace predicate (not hidden),
        // but the lookup contract is the same: the relation is present regardless of
        // any conditional gate.
        $resource = new RoleAwareResource();

        self::assertNotNull($resource->relationNamed('owner'));
        self::assertNotNull($resource->relationNamed('audit'));
    }

    #[Test]
    public function getRelationshipsExcludesAConditionallyHiddenRelationFromTheRender(): void
    {
        // A relation hidden only for this caller is excluded from the rendered
        // relationship callables, while the static relationFields()/relationNamed()
        // lookup still finds it (proven above).
        $resource = new ConditionallyHiddenRelationResource();
        $resource->setSerializerResolver(new StubSerializerResolver('users'));

        $forGuest = $resource->getRelationships(['id' => '1'], $this->roleRequest(null));
        self::assertArrayNotHasKey('owner', $forGuest);

        $forAdmin = $resource->getRelationships(['id' => '1'], $this->roleRequest('admin'));
        self::assertArrayHasKey('owner', $forAdmin);

        // The build-time lookup resolves it for either caller.
        self::assertNotNull($resource->relationNamed('owner'));
    }

    /**
     * Builds a GET request optionally carrying `X-Role: admin`, for the read /
     * include sites that consult the request without a body.
     */
    private function roleRequest(?string $role): JsonApiRequest
    {
        $headers = $role !== null ? ['X-Role' => $role] : [];

        return new JsonApiRequest(new ServerRequest('GET', '/api/role_aware', $headers));
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
            'password' => 'never echoed',
            'author' => ['id' => '1', 'type' => 'users'],
            'comments' => [],
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers extra request headers (e.g. `X-Role`
     *                                        consumed by a request-aware predicate)
     */
    private function createRequest(string $method, array $body, array $headers = []): JsonApiRequest
    {
        $psr = (new ServerRequest($method, '/api/posts'))
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/vnd.api+json');

        foreach ($headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        return new JsonApiRequest($psr);
    }

    private function resolver(): \haddowg\JsonApi\Resource\SerializerResolverInterface
    {
        return new StubSerializerResolver();
    }

    /**
     * A resolver mapping the intermediate `publishers` type of an `on()` chain to a
     * real {@see AbstractResource} declaring the `country` to-one relation, so the
     * multi-hop chain walk can resolve hop 1 (`country`) off the publisher type.
     */
    private function chainResolver(): \haddowg\JsonApi\Resource\SerializerResolverInterface
    {
        return new class implements \haddowg\JsonApi\Resource\SerializerResolverInterface {
            private PublisherChainResource $publisher;

            public function __construct()
            {
                $this->publisher = new PublisherChainResource();
            }

            public function serializerFor(string $type): SerializerInterface
            {
                return $type === 'publishers'
                    ? $this->publisher
                    : throw new \haddowg\JsonApi\Exception\ResourceNotFound();
            }

            public function hasSerializerFor(string $type): bool
            {
                return $type === 'publishers';
            }

            public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface
            {
                return null;
            }

            public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface
            {
                return null;
            }

            public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface
            {
                return null;
            }

            public function relationshipLinkage(): ?\haddowg\JsonApi\Serializer\RelationshipLinkageInterface
            {
                return null;
            }
        };
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
            Str::make('password')->writeOnly()->minLength(8),
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

    public function pagination(?\haddowg\JsonApi\Pagination\PaginatorInterface $serverDefault): \haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return PagePaginator::make()->withDefaultPerPage(15);
    }
}

/**
 * A resource flattening scalar attributes from a hidden, to-one `author` relation
 * via {@see \haddowg\JsonApi\Resource\Field\AbstractField::on()}.
 */
final class FlattenedResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            // Reads/writes the related author's `name` (storedAs) and `displayName`.
            Str::make('authorName')->on('author')->storedAs('name'),
            Str::make('authorDisplay')->on('author')->storedAs('displayName'),
            // The backing relation is hidden: never rendered as a relationship.
            BelongsTo::make('author')->type('authors')->hidden(),
        ];
    }
}

/**
 * A resource flattening attributes through a multi-hop to-one chain
 * (`publisher.country`) as well as a single-hop one (`author`), exercising the
 * dedup eager-load set and the chain walk.
 */
final class MultiHopFlattenedResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            // Single-hop, deduped across both attributes -> one `author` eager path.
            Str::make('authorName')->on('author')->storedAs('name'),
            Str::make('authorDisplay')->on('author')->storedAs('displayName'),
            // Multi-hop: book -> publisher -> country, reads the country's `name`.
            Str::make('countryName')->on('publisher.country')->storedAs('name'),
            BelongsTo::make('author')->type('authors')->hidden(),
            BelongsTo::make('publisher')->type('publishers')->hidden(),
        ];
    }
}

/**
 * Like {@see MultiHopFlattenedResource} but its intermediate `publisher` relation
 * is {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::storedAs()} a different
 * member (`imprint`), proving the chain walk honours each hop's column().
 */
final class StoredAsHopFlattenedResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('countryName')->on('publisher.country')->storedAs('name'),
            BelongsTo::make('publisher')->type('publishers')->storedAs('imprint')->hidden(),
        ];
    }
}

/**
 * The intermediate type of an `on('publisher.country')` chain: declares the
 * `country` to-one relation the chain walk follows on hop 1.
 */
final class PublisherChainResource extends AbstractResource
{
    public static string $type = 'publishers';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('country')->type('countries')->hidden(),
        ];
    }
}

/**
 * Like {@see FlattenedResource} but its `author` relation materialises a real
 * related object on hydrate (via `fillUsing`), so a flattened write in the same
 * create body lands on the freshly associated model.
 */
final class FlattenedWithSettableRelationResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('authorName')->on('author')->storedAs('name'),
            BelongsTo::make('author')->type('authors')->fillUsing(
                static function (mixed $model, mixed $relationship): mixed {
                    $id = $relationship instanceof \haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship
                        ? $relationship->resourceIdentifier?->id
                        : null;
                    if ($model instanceof \stdClass) {
                        $model->author = (object) ['id' => $id, 'name' => null];
                    }

                    return $model;
                },
            ),
        ];
    }
}

/**
 * A resource declaring an `on()` attribute against a to-many relation — an author
 * error surfaced at first use (on() requires a to-one relation).
 */
final class FlattenedToManyResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('tagName')->on('tags'),
            HasMany::make('tags')->type('tags')->hidden(),
        ];
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
 * A resource declaring a relation literally named `_self_`, exercising the
 * build-time collision guard against the reserved `?withCount` token.
 */
final class SelfTokenRelationResource extends AbstractResource
{
    public static string $type = 'collisions';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('_self_')->type('others'),
        ];
    }
}

/**
 * A resource whose id is the wire form of a distinct storage key, exercising the
 * {@see ReversingIdEncoder} decode-on-create path. Accepts client-generated ids.
 */
final class EncodedIdResource extends AbstractResource
{
    public static string $type = 'encoded';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new ReversingIdEncoder())->allowClientId(),
        ];
    }
}

/**
 * A resource whose encoder rejects every wire id, exercising the 422 safety net
 * behind {@see \haddowg\JsonApi\Exception\ResourceIdUndecodable}.
 */
final class RejectingIdResource extends AbstractResource
{
    public static string $type = 'rejected';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new RejectingIdEncoder())->allowClientId(),
        ];
    }
}

/**
 * A resource that attaches an encoder but does NOT accept client-generated ids, so
 * every create falls through to a server-generated id (here a closure). The encoder
 * rejects every wire id ({@see RejectingIdEncoder}), proving the server-generated
 * value is stored as-is and never fed to decode() (which would 422 the create).
 */
final class ServerGeneratedEncodedIdResource extends AbstractResource
{
    public const string GENERATED_ID = 'server-minted-id';

    public static string $type = 'server-encoded';

    public function fields(): array
    {
        return [
            Id::make()
                ->encodeUsing(new RejectingIdEncoder())
                ->generateUsing(static fn(): string => self::GENERATED_ID),
        ];
    }
}

/**
 * The store-provided default: a plain {@see Id::make()} with no fallback, so a
 * create with no client id sets nothing and leaves the id for the store to assign.
 */
final class StoreProvidedIdResource extends AbstractResource
{
    public static string $type = 'store-provided';

    public function fields(): array
    {
        return [
            Id::make(),
        ];
    }
}

/**
 * {@see Id::allowClientId()}: a client id is optional — used when supplied, falling
 * back to store-provided otherwise.
 */
final class OptionalClientIdResource extends AbstractResource
{
    public static string $type = 'optional';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
        ];
    }
}

/**
 * {@see Id::requireClientId()}: a client id is mandatory — its absence is a `403`
 * {@see \haddowg\JsonApi\Exception\ClientGeneratedIdRequired}.
 */
final class RequiredClientIdResource extends AbstractResource
{
    public static string $type = 'required';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId(),
        ];
    }
}

/**
 * {@see Id::generated()} over a `uuid()` format: core mints a v4 UUID when no
 * client id is supplied.
 */
final class GeneratedUuidResource extends AbstractResource
{
    public static string $type = 'gen-uuid';

    public function fields(): array
    {
        return [
            Id::make()->uuid()->generated(),
        ];
    }
}

/**
 * {@see Id::generated()} over a `ulid()` format: core mints a Crockford-base32 ULID.
 */
final class GeneratedUlidResource extends AbstractResource
{
    public static string $type = 'gen-ulid';

    public function fields(): array
    {
        return [
            Id::make()->ulid()->generated(),
        ];
    }
}

/**
 * {@see Id::generateUsing()}: a closure returns the generated storage key directly.
 */
final class GeneratedClosureResource extends AbstractResource
{
    public const string GENERATED_ID = 'closure-minted';

    public static string $type = 'gen-closure';

    public function fields(): array
    {
        return [
            Id::make()->generateUsing(static fn(): string => self::GENERATED_ID),
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

/**
 * A resource declaring **every** request-aware predicate, each gated on whether
 * the request carries `X-Role: admin`, so a single fixture exercises the render /
 * hydrate / mutate / include sites against a privileged vs an ordinary caller.
 */
final class RoleAwareResource extends AbstractResource
{
    public static string $type = 'role_aware';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            // Hidden from a non-admin caller, rendered for an admin.
            Str::make('secret')->hidden(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => self::nonAdmin($request),
            ),
            // Write-only for a non-admin caller (accepted but never echoed).
            Str::make('token')->writeOnly(
                static fn(JsonApiRequestInterface $request): bool => self::nonAdmin($request),
            ),
            // Read-only on update for a non-admin caller (their PATCH is ignored).
            Str::make('locked')->readOnlyOnUpdate(
                static fn(JsonApiRequestInterface $request): bool => self::nonAdmin($request),
            ),
            // Replacement prohibited for a non-admin caller.
            BelongsTo::make('owner')->type('users')->cannotReplace(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => self::nonAdmin($request),
            ),
            // Inclusion prohibited for a non-admin caller.
            BelongsTo::make('audit')->type('audits')->cannotBeIncluded(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => self::nonAdmin($request),
            ),
        ];
    }

    private static function nonAdmin(JsonApiRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Role') !== 'admin';
    }
}

/**
 * A resource whose `owner` relation is hidden only for a non-admin caller,
 * exercising the build-time-static / render-request-aware split in
 * {@see AbstractResource::getRelationships()} (excluded from the render) vs
 * {@see AbstractResource::relationNamed()} (still resolvable for the 403 path).
 */
final class ConditionallyHiddenRelationResource extends AbstractResource
{
    public static string $type = 'conditionally_hidden';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('owner')->type('users')->hidden(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
            ),
        ];
    }
}
