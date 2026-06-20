<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Server::class)]
#[CoversClass(\haddowg\JsonApi\Server\ResourceRegistry::class)]
#[CoversClass(\haddowg\JsonApi\Server\Entry::class)]
#[CoversClass(\haddowg\JsonApi\Server\Internal\MiddlewareDecorator::class)]
#[CoversClass(\haddowg\JsonApi\Negotiation\StrictQueryParameterValidator::class)]
#[CoversClass(\haddowg\JsonApi\Exception\FieldsetMemberUnrecognized::class)]
#[CoversClass(\haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter::class)]
#[CoversClass(NoResourceRegistered::class)]
#[Group('spec:crud')]
final class ServerTest extends TestCase
{
    #[Test]
    public function defaultsAndFluentConfiguration(): void
    {
        $server = Server::make()
            ->withBaseUri('https://example.com/api/v1')
            ->withVersion('1.1')
            ->withDefaultMeta(['env' => 'test'])
            ->withEncodeOptions(\JSON_UNESCAPED_UNICODE);

        self::assertSame('https://example.com/api/v1', $server->baseUri());
        self::assertSame('1.1', $server->jsonApiVersion());
        self::assertSame(['env' => 'test'], $server->defaultMeta());
        self::assertSame(\JSON_UNESCAPED_UNICODE, $server->encodeOptions());
    }

    #[Test]
    public function withMethodsAreImmutable(): void
    {
        $base = Server::make();
        $configured = $base->withBaseUri('https://example.com');

        self::assertNotSame($base, $configured);
        self::assertSame('', $base->baseUri());
        self::assertSame('https://example.com', $configured->baseUri());
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function withRelationshipPaginationThreadsIntoTheResolverAndSurvivesFurtherWithers(): void
    {
        $resolver = new \haddowg\JsonApi\Tests\Double\FakeRelationshipPagination(null);

        $base = Server::make();
        self::assertNull($base->relationshipPagination());
        self::assertNull($base->resources()->relationshipPagination());

        $configured = $base->withRelationshipPagination($resolver);

        // Immutable, threaded into the registry (the resolver relations consult).
        self::assertNull($base->relationshipPagination());
        self::assertSame($resolver, $configured->relationshipPagination());
        self::assertSame($resolver, $configured->resources()->relationshipPagination());

        // A subsequent unrelated wither re-pushes the resolver into the new registry.
        $rebased = $configured->withBaseUri('https://api.example.com');
        self::assertSame($resolver, $rebased->resources()->relationshipPagination());
    }

    #[Test]
    public function maxIncludeDepthIsUnlimitedByDefaultAndSettable(): void
    {
        $base = Server::make();
        self::assertNull($base->maxIncludeDepth());

        $configured = $base->withMaxIncludeDepth(3);
        self::assertNull($base->maxIncludeDepth());
        self::assertSame(3, $configured->maxIncludeDepth());
    }

    #[Test]
    public function registerIsImmutableAndDoesNotLeak(): void
    {
        $base = Server::make();
        $withPost = $base->register(PostResource::class);

        self::assertFalse($base->resources()->has('posts'));
        self::assertTrue($withPost->resources()->has('posts'));
    }

    #[Test]
    public function schemaSatisfiesBothContractsByDefault(): void
    {
        $server = Server::make()->register(PostResource::class);

        self::assertInstanceOf(PostResource::class, $server->serializerFor('posts'));
        self::assertInstanceOf(PostResource::class, $server->hydratorFor('posts'));
    }

    #[Test]
    public function serializerOverrideTakesPrecedence(): void
    {
        $server = Server::make()->register(PostResource::class, serializer: CustomPostSerializer::class);

        self::assertInstanceOf(CustomPostSerializer::class, $server->serializerFor('posts'));
        // Hydration still falls back to the schema.
        self::assertInstanceOf(PostResource::class, $server->hydratorFor('posts'));
    }

    #[Test]
    public function hydratorOverrideTakesPrecedence(): void
    {
        $server = Server::make()->register(PostResource::class, hydrator: CustomPostHydrator::class);

        self::assertInstanceOf(CustomPostHydrator::class, $server->hydratorFor('posts'));
        self::assertInstanceOf(PostResource::class, $server->serializerFor('posts'));
    }

    #[Test]
    public function unknownTypeThrowsNoResourceRegistered(): void
    {
        $server = Server::make()->register(PostResource::class);

        try {
            $server->serializerFor('widgets');
            self::fail('Expected NoResourceRegistered.');
        } catch (NoResourceRegistered $e) {
            self::assertSame('widgets', $e->type);
            self::assertSame(500, $e->getStatusCode());
        }
    }

    #[Test]
    public function duplicateTypeRegistrationThrows(): void
    {
        $this->expectException(\LogicException::class);

        Server::make()
            ->register(PostResource::class)
            ->register(PostResource::class);
    }

    #[Test]
    public function resolverConstructsResourcesLazily(): void
    {
        $resolver = new RecordingResolver();
        $server = Server::make()
            ->withContainer($resolver)
            ->register(PostResource::class);

        // Registration must not construct the resource: the type is read from the
        // static $type, so the resolver is untouched until first lookup.
        self::assertSame([], $resolver->calls);

        $first = $server->serializerFor('posts');
        self::assertInstanceOf(PostResource::class, $first);
        self::assertSame([PostResource::class], $resolver->calls);

        // Subsequent lookups are cached: the resolver is not consulted again, and
        // the same instance is handed back.
        $second = $server->resources()->resourceFor('posts');
        self::assertSame($first, $second);
        self::assertSame([PostResource::class], $resolver->calls);
    }

    #[Test]
    public function psr11ContainerResolvesRegisteredClasses(): void
    {
        $resource = new PostResource();
        $serializer = new CustomPostSerializer();
        $hydrator = new CustomPostHydrator();

        $container = new ArrayContainer([
            PostResource::class => $resource,
            CustomPostSerializer::class => $serializer,
            CustomPostHydrator::class => $hydrator,
        ]);

        $server = Server::make()
            ->withContainer($container)
            ->register(PostResource::class, serializer: CustomPostSerializer::class, hydrator: CustomPostHydrator::class);

        self::assertSame($serializer, $server->serializerFor('posts'));
        self::assertSame($hydrator, $server->hydratorFor('posts'));
        self::assertSame($resource, $server->resources()->resourceFor('posts'));
    }

    #[Test]
    public function withContainerIsImmutableAndDoesNotLeak(): void
    {
        $base = Server::make()->register(PostResource::class);
        $resolver = new RecordingResolver();
        $configured = $base->withContainer($resolver);

        self::assertNotSame($base, $configured);

        // The base server still uses plain `new` — the resolver did not leak in.
        self::assertInstanceOf(PostResource::class, $base->serializerFor('posts'));
        self::assertSame([], $resolver->calls);

        // The configured server resolves through the injected factory.
        self::assertInstanceOf(PostResource::class, $configured->serializerFor('posts'));
        self::assertSame([PostResource::class], $resolver->calls);
    }

    #[Test]
    public function withContainerIsOrderIndependent(): void
    {
        $before = new RecordingResolver();
        $serverBefore = Server::make()
            ->withContainer($before)
            ->register(PostResource::class);

        $after = new RecordingResolver();
        $serverAfter = Server::make()
            ->register(PostResource::class)
            ->withContainer($after);

        self::assertInstanceOf(PostResource::class, $serverBefore->serializerFor('posts'));
        self::assertInstanceOf(PostResource::class, $serverAfter->serializerFor('posts'));

        self::assertSame([PostResource::class], $before->calls);
        self::assertSame([PostResource::class], $after->calls);
    }

    #[Test]
    public function resolverReturningWrongTypeThrowsLogicException(): void
    {
        $server = Server::make()
            ->withContainer(static fn(string $class): object => new \stdClass())
            ->register(PostResource::class);

        $this->expectException(\LogicException::class);
        $server->serializerFor('posts');
    }

    #[Test]
    public function barePairResolvesSerializerAndHydratorWithoutResourceClass(): void
    {
        $server = Server::make()->registerSerializerHydrator(
            'widgets',
            serializer: CustomWidgetSerializer::class,
            hydrator: CustomPostHydrator::class,
        );

        self::assertTrue($server->hasSerializerFor('widgets'));
        self::assertInstanceOf(CustomWidgetSerializer::class, $server->serializerFor('widgets'));
        self::assertInstanceOf(CustomPostHydrator::class, $server->hydratorFor('widgets'));

        // A bare pair has no Resource fallback.
        $this->expectException(NoResourceRegistered::class);
        $server->resources()->resourceFor('widgets');
    }

    #[Test]
    public function barePairIsBuiltThroughTheInjectedResolver(): void
    {
        $resolver = new RecordingResolver();
        $server = Server::make()
            ->withContainer($resolver)
            ->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        self::assertSame([], $resolver->calls);

        self::assertInstanceOf(CustomWidgetSerializer::class, $server->serializerFor('widgets'));
        self::assertSame([CustomWidgetSerializer::class], $resolver->calls);
    }

    #[Test]
    public function dispatchInvokesTheOperationHandler(): void
    {
        $response = MetaResponse::fromMeta(['ok' => true]);
        $handler = new RecordingOperationHandler($response);
        $server = Server::make()->withHandler($handler);

        $operation = $this->stubOperation();
        $result = $server->dispatch($operation);

        self::assertSame($response, $result);
        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function dispatchWithoutOperationHandlerThrows(): void
    {
        $server = Server::make();

        $this->expectException(\LogicException::class);
        $server->dispatch($this->stubOperation());
    }

    #[Test]
    public function withServingIsImmutableAndAppends(): void
    {
        $base = Server::make();
        self::assertSame([], $base->serving());

        $first = static function (JsonApiRequestInterface $request): void {};
        $second = static function (JsonApiRequestInterface $request): void {};

        $withOne = $base->withServing($first);
        $withTwo = $withOne->withServing($second);

        // Each wither is immutable and does not leak into the source instance.
        self::assertNotSame($base, $withOne);
        self::assertNotSame($withOne, $withTwo);
        self::assertSame([], $base->serving());
        self::assertSame([$first], $withOne->serving());
        self::assertSame([$first, $second], $withTwo->serving());
    }

    #[Test]
    public function servingHandlerFiresOnceBeforeTheOperationHandler(): void
    {
        $order = [];
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta(['ok' => true]));
        $request = StubJsonApiRequest::create();

        $server = Server::make()
            ->withHandler($handler)
            ->withServing(static function (JsonApiRequestInterface $received) use (&$order, $request): void {
                self::assertSame($request, $received);
                $order[] = 'serving';
            });

        // The operation handler records itself only when it runs.
        $operation = $this->stubOperation($request);
        $result = $server->dispatch($operation);

        self::assertSame(['serving'], $order);
        self::assertSame($operation, $handler->received);
        self::assertInstanceOf(MetaResponse::class, $result);
    }

    #[Test]
    public function multipleServingHandlersFireInRegistrationOrder(): void
    {
        $order = [];
        $server = Server::make()
            ->withHandler(new RecordingOperationHandler(MetaResponse::fromMeta([])))
            ->withServing(static function (JsonApiRequestInterface $request) use (&$order): void {
                $order[] = 'a';
            })
            ->withServing(static function (JsonApiRequestInterface $request) use (&$order): void {
                $order[] = 'b';
            });

        $server->dispatch($this->stubOperation(StubJsonApiRequest::create()));

        self::assertSame(['a', 'b'], $order);
    }

    #[Test]
    public function aThrowingServingHandlerAbortsBeforeTheOperationHandler(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->withServing(static function (JsonApiRequestInterface $request): void {
                throw new ServingDenied();
            });

        try {
            $server->dispatch($this->stubOperation(StubJsonApiRequest::create()));
            self::fail('Expected the serving handler to abort dispatch.');
        } catch (ServingDenied $e) {
            // The JSON:API exception propagates out of dispatch() unchanged.
            self::assertSame(403, $e->getStatusCode());
        }

        // The operation handler never ran.
        self::assertNull($handler->received);
    }

    #[Test]
    public function servingDoesNotFireForAProgrammaticDispatchWithNoHttpRequest(): void
    {
        $fired = false;
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->withServing(static function (JsonApiRequestInterface $request) use (&$fired): void {
                $fired = true;
            });

        // No HTTP message backs the operation, so there is no request to gate.
        $operation = $this->stubOperation();
        $server->dispatch($operation);

        self::assertFalse($fired);
        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function strictQueryParametersIsOnByDefaultAndFluent(): void
    {
        $server = Server::make();
        self::assertTrue($server->strictQueryParameters());
        self::assertSame([], $server->customQueryParameters());

        $relaxed = $server->withStrictQueryParameters(false);
        self::assertFalse($relaxed->strictQueryParameters());
        // Immutable: the source is untouched.
        self::assertTrue($server->strictQueryParameters());

        $withCustom = $server->withCustomQueryParameter('withTrashed', 'myFilter');
        self::assertSame(['withTrashed', 'myFilter'], $withCustom->customQueryParameters());
        self::assertSame([], $server->customQueryParameters());
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function dispatchRejectsAnUnrecognizedQueryParameterByDefault(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()->withHandler($handler);

        $operation = $this->stubOperation(StubJsonApiRequest::create(['bogus' => '1']));

        try {
            $server->dispatch($operation);
            self::fail('Expected the unrecognized query parameter to be rejected.');
        } catch (\haddowg\JsonApi\Exception\QueryParamUnrecognized $e) {
            self::assertSame('bogus', $e->unrecognizedQueryParam);
            self::assertSame(400, $e->getStatusCode());
        }

        // The 400 fires before the operation handler runs.
        self::assertNull($handler->received);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function dispatchIgnoresAnUnrecognizedQueryParameterWhenRelaxed(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta(['ok' => true]));
        $server = Server::make()
            ->withHandler($handler)
            ->withStrictQueryParameters(false);

        $operation = $this->stubOperation(StubJsonApiRequest::create(['bogus' => '1']));
        $result = $server->dispatch($operation);

        // Tolerant: the param is ignored and the operation runs as before.
        self::assertInstanceOf(MetaResponse::class, $result);
        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function dispatchAllowsTheReservedFamiliesAndTheNegotiatedWithCountProfile(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->withProfile(new \haddowg\JsonApi\Schema\Profile\CountableProfile());

        // `withCount` is recognized only when the Countable profile it
        // belongs to is registered and negotiated in the Accept `profile` parameter.
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['posts' => 'title'],
            'include' => 'author',
            'sort' => '-title',
            'page' => ['number' => '1'],
            'filter' => ['draft' => '0'],
            'withCount' => 'comments',
        ], [
            'Accept' => 'application/vnd.api+json;profile="' . \haddowg\JsonApi\Schema\Profile\CountableProfile::URI . '"',
        ]));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function dispatchAllowsAHostRegisteredCustomQueryParameter(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->withCustomQueryParameter('withTrashed');

        $operation = $this->stubOperation(StubJsonApiRequest::create(['withTrashed' => '1']));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function dispatchRejectsAProfileFamilyWhenTheProfileIsNotNegotiated(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->withProfile(new \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile());

        // No Accept profile negotiated -> the relatedQuery family is unrecognized.
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'relatedQuery' => ['author' => ['sort' => 'name']],
        ]));

        $this->expectException(\haddowg\JsonApi\Exception\QueryParamUnrecognized::class);

        $server->dispatch($operation);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function dispatchAllowsAProfileFamilyWhenTheProfileIsNegotiated(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->withProfile(new \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile());

        $request = (new \haddowg\JsonApi\Request\JsonApiRequest(
            (new ServerRequest('GET', '/'))
                ->withHeader(
                    'accept',
                    'application/vnd.api+json;profile="' . \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile::URI . '"',
                )
                ->withQueryParams(['relatedQuery' => ['author' => ['sort' => 'name']]]),
        ));

        $operation = $this->stubOperation($request);

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchRejectsAnUnknownSparseFieldsetMemberByDefault(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class);

        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['posts' => 'title,bogus'],
        ]));

        try {
            $server->dispatch($operation);
            self::fail('Expected the unknown sparse-fieldset member to be rejected.');
        } catch (\haddowg\JsonApi\Exception\FieldsetMemberUnrecognized $e) {
            self::assertSame('posts', $e->type);
            self::assertSame(['bogus'], $e->unrecognizedMembers);
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('fields', $e->getErrors()[0]->source?->parameter);
            self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $e->getErrors()[0]->code);
        }

        // The 400 fires before the operation handler runs.
        self::assertNull($handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchToleratesAnUnknownSparseFieldsetMemberWhenRelaxed(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta(['ok' => true]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class)
            ->withStrictQueryParameters(false);

        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['posts' => 'title,bogus'],
        ]));
        $result = $server->dispatch($operation);

        self::assertInstanceOf(MetaResponse::class, $result);
        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchToleratesHiddenWriteOnlyNonSparseIdAndRelationMembers(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class);

        // Every declared field name is tolerated: a hidden attribute, a write-only
        // attribute, a conditionally-hidden attribute, a non-sparse attribute, the
        // id, and both relationship names.
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['posts' => 'title,secret,password,draftNote,slug,id,author,comments'],
        ]));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchToleratesAnEmptySparseFieldset(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class);

        // `?fields[posts]=` is a valid request meaning "render no fields of this
        // type" — the empty-string sentinel member is not an unknown member.
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['posts' => ''],
        ]));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchToleratesATrailingCommaInASparseFieldset(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class);

        // A trailing/leading/double comma yields an empty-string sentinel member
        // alongside the real ones; the sentinel must not be flagged as unknown.
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['posts' => 'title,'],
        ]));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchToleratesASparseFieldsetForAnUnregisteredType(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class);

        // An unknown TYPE key is out of scope for member validation — tolerated.
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['bogusType' => 'anything,at,all'],
        ]));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchToleratesASparseFieldsetForABareSerializerWithNoFieldInventory(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class)
            ->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        // The widgets serializer declares no field namespace, so its members are
        // tolerated (no DeclaresFieldNamesInterface).
        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'fields' => ['widgets' => 'whatever'],
        ]));

        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function dispatchValidatesASparseFieldsetForAnIncludedTypeEvenWhenThePrimaryIsEmpty(): void
    {
        // The validation is registry-based and runs pre-render, so a fields[type]
        // for a SECONDARY (included) type is validated regardless of whether the
        // primary collection returns any rows — the target type here is not even
        // the dispatched `posts`.
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withHandler($handler)
            ->register(FieldsetPostResource::class)
            ->register(FieldsetAuthorResource::class);

        $operation = $this->stubOperation(StubJsonApiRequest::create([
            'include' => 'author',
            'fields' => ['posts' => 'title', 'authors' => 'name,bogus'],
        ]));

        try {
            $server->dispatch($operation);
            self::fail('Expected the unknown member on the included type to be rejected.');
        } catch (\haddowg\JsonApi\Exception\FieldsetMemberUnrecognized $e) {
            self::assertSame('authors', $e->type);
            self::assertSame(['bogus'], $e->unrecognizedMembers);
        }

        self::assertNull($handler->received);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function thePsr15HandlePathEnforcesStrictSparseFieldsetMembersToo(): void
    {
        // The adapter runs the same strict-validation hook on the handle() path, so
        // an unknown sparse-fieldset member is rejected there too.
        $psr17 = new Psr17Factory();
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->register(FieldsetPostResource::class);

        $request = (new ServerRequest('GET', '/api/posts?fields[posts]=title,bogus'))
            ->withQueryParams(['fields' => ['posts' => 'title,bogus']])
            ->withAttribute(\haddowg\JsonApi\Operation\Target::class, new \haddowg\JsonApi\Operation\Target('posts'));

        try {
            $server->handle($request);
            self::fail('Expected the PSR-15 path to reject the unknown member.');
        } catch (\haddowg\JsonApi\Exception\FieldsetMemberUnrecognized $e) {
            self::assertSame('posts', $e->type);
            self::assertSame(['bogus'], $e->unrecognizedMembers);
        }

        self::assertNull($handler->received);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function thePsr15HandlePathEnforcesStrictQueryParametersToo(): void
    {
        // An OperationHandler is wrapped in the adapter, which the server hands the
        // strict-validation hook — so handle() rejects an unrecognized family too.
        $psr17 = new Psr17Factory();
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler);

        $request = (new ServerRequest('GET', '/api/posts?bogus=1'))
            ->withQueryParams(['bogus' => '1'])
            ->withAttribute(\haddowg\JsonApi\Operation\Target::class, new \haddowg\JsonApi\Operation\Target('posts'));

        try {
            $server->handle($request);
            self::fail('Expected the PSR-15 handle() path to reject the unrecognized param.');
        } catch (\haddowg\JsonApi\Exception\QueryParamUnrecognized $e) {
            self::assertSame('bogus', $e->unrecognizedQueryParam);
        }

        self::assertNull($handler->received);
    }

    #[Test]
    public function handleRunsTheMiddlewareChainInOrder(): void
    {
        $psr17 = new Psr17Factory();
        $inner = new class ($psr17->createResponse(204)) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $order = $request->getAttribute('order');
                $order = \is_array($order) ? $order : [];
                $order[] = 'handler';
                $parts = \array_map(static fn(mixed $v): string => \is_string($v) ? $v : '', $order);

                return $this->response->withHeader('X-Order', \implode(',', $parts));
            }
        };

        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMiddleware([new TaggingMiddleware('a'), new TaggingMiddleware('b')])
            ->withHandler($inner);

        $response = $server->handle(new ServerRequest('GET', '/api/posts'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('a,b,handler', $response->getHeaderLine('X-Order'));
    }

    #[Test]
    public function multipleServersRunTheirOwnConfiguration(): void
    {
        $psr17 = new Psr17Factory();
        $v1 = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMiddleware([new TaggingMiddleware('v1')])
            ->withHandler($this->orderEcho($psr17));
        $v2 = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMiddleware([new TaggingMiddleware('v2a'), new TaggingMiddleware('v2b')])
            ->withHandler($this->orderEcho($psr17));

        self::assertSame('v1,handler', $v1->handle(new ServerRequest('GET', '/v1'))->getHeaderLine('X-Order'));
        self::assertSame('v2a,v2b,handler', $v2->handle(new ServerRequest('GET', '/v2'))->getHeaderLine('X-Order'));
    }

    private function orderEcho(Psr17Factory $psr17): RequestHandlerInterface
    {
        return new class ($psr17->createResponse(200)) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $order = $request->getAttribute('order');
                $order = \is_array($order) ? $order : [];
                $order[] = 'handler';
                $parts = \array_map(static fn(mixed $v): string => \is_string($v) ? $v : '', $order);

                return $this->response->withHeader('X-Order', \implode(',', $parts));
            }
        };
    }

    private function stubOperation(?ServerRequestInterface $httpRequest = null): \haddowg\JsonApi\Operation\JsonApiOperationInterface
    {
        return new class ($httpRequest) implements \haddowg\JsonApi\Operation\JsonApiOperationInterface {
            public function __construct(private readonly ?ServerRequestInterface $httpRequest) {}

            public function target(): \haddowg\JsonApi\Operation\Target
            {
                return new \haddowg\JsonApi\Operation\Target('posts');
            }

            public function queryParameters(): \haddowg\JsonApi\Operation\QueryParameters
            {
                return new \haddowg\JsonApi\Operation\QueryParameters([], [], [], [], []);
            }

            public function context(): \haddowg\JsonApi\Operation\OperationContext
            {
                return new \haddowg\JsonApi\Operation\OperationContext(
                    new \haddowg\JsonApi\Tests\Double\StubServer(),
                    $this->httpRequest,
                );
            }
        };
    }
}

/**
 * Appends its tag to the request `order` attribute before delegating, so a
 * test can assert the chain ran in the configured order.
 */
final readonly class TaggingMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $order = $request->getAttribute('order');
        $order = \is_array($order) ? $order : [];
        $order[] = $this->tag;

        return $handler->handle($request->withAttribute('order', $order));
    }
}

final class PostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}

/**
 * A resource exercising every member-namespace edge case for strict
 * sparse-fieldset member validation: a plain attribute, a hidden attribute, a
 * write-only attribute, a conditionally-hidden attribute, a non-sparse
 * attribute, a to-one relationship and a to-many relationship — all of which are
 * declared field names and therefore TOLERATED as `fields[type]` members.
 */
final class FieldsetPostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('secret')->hidden(),
            Str::make('password')->writeOnly(),
            Str::make('draftNote')->hidden(static fn(JsonApiRequestInterface $request, mixed $model): bool => true),
            Str::make('slug')->notSparseField(),
            \haddowg\JsonApi\Resource\Field\BelongsTo::make('author')->type('authors'),
            \haddowg\JsonApi\Resource\Field\HasMany::make('comments')->type('comments'),
        ];
    }
}

final class FieldsetAuthorResource extends AbstractResource
{
    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

final class CustomPostSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'posts';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

final class CustomPostHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        return $domainObject;
    }
}

final class CustomWidgetSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'widgets';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

/**
 * A `callable(class-string): object` resolver that records every class it built
 * and constructs it with plain `new`, so a test can assert lazy, once-only
 * construction.
 */
final class RecordingResolver
{
    /**
     * @var list<class-string>
     */
    public array $calls = [];

    /**
     * @param class-string $class
     */
    public function __invoke(string $class): object
    {
        $this->calls[] = $class;

        return new $class();
    }
}

/**
 * A minimal PSR-11 container backed by a preconfigured class-string => instance
 * map, for exercising the container branch of the resolver.
 */
final class ArrayContainer implements \Psr\Container\ContainerInterface
{
    /**
     * @param array<class-string, object> $instances
     */
    public function __construct(private readonly array $instances) {}

    public function get(string $id): object
    {
        return $this->instances[$id]
            ?? throw new class ('No entry for ' . $id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }
}

/**
 * A 403 JSON:API exception a `serving` handler throws to abort the request,
 * modelling an authorization gate. It propagates out of {@see Server::dispatch()}
 * unchanged.
 */
final class ServingDenied extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Serving denied.', 403);
    }

    public function getErrors(): array
    {
        return [];
    }
}
