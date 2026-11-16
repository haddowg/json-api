<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresNamedOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresSingleton;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationEnumerator;
use haddowg\JsonApi\OpenApi\PathTemplate;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\SingletonMisdeclared;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\DelegatingTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\LinkDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeDeclaringServerMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A singleton type's paths hang off `/{uriType}` with no `{id}`, keeping their operation ids,
 * and everything that follows a path, from examples to `describedby`, follows it there.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationEnumerator::class)]
#[CoversClass(PathTemplate::class)]
#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(GeneratorContract::class)]
#[CoversClass(SingletonMisdeclared::class)]
final class SingletonProjectionTest extends TestCase
{
    #[Test]
    public function itsPathsCarryNoIdAndKeepTheirOperationIds(): void
    {
        $paths = self::arr($this->project()['paths']);

        self::assertSame(
            ['/inbox', '/inbox/owner', '/inbox/relationships/owner', '/inbox/-actions/mark-all-read'],
            \array_values(\array_filter(\array_keys($paths), static fn(string $path): bool => $path === '/inbox' || \str_starts_with($path, '/inbox/'))),
        );
        self::assertSame('fetchOne.inboxes', self::at($paths, '/inbox', 'get', 'operationId'));
        self::assertSame('update.inboxes', self::at($paths, '/inbox', 'patch', 'operationId'));
        self::assertSame('fetchRelated.inboxes.owner', self::at($paths, '/inbox/owner', 'get', 'operationId'));
        self::assertSame('fetchRelationship.inboxes.owner', self::at($paths, '/inbox/relationships/owner', 'get', 'operationId'));
        self::assertSame('updateRelationship.inboxes.owner', self::at($paths, '/inbox/relationships/owner', 'patch', 'operationId'));
        self::assertSame('action.inboxes.mark-all-read', self::at($paths, '/inbox/-actions/mark-all-read', 'post', 'operationId'));

        foreach (['/inbox', '/inbox/owner', '/inbox/relationships/owner', '/inbox/-actions/mark-all-read'] as $path) {
            self::assertNull(self::at($paths, $path, 'parameters'), $path);
        }
        self::assertArrayNotHasKey('delete', self::arr($paths['/inbox']));
        self::assertArrayNotHasKey('post', self::arr($paths['/inbox']));
    }

    #[Test]
    public function anotherTypesRelatedEndpointRendersItAsUsual(): void
    {
        $document = $this->project();
        $response = self::at($document, 'paths', '/inbox-items/{id}/inbox', 'get', 'responses', '200', 'content', 'application/vnd.api+json');

        self::assertSame('#/components/schemas/InboxItemsInboxRelatedDocument', self::at($response, 'schema', '$ref'));
        self::assertSame('#/components/schemas/InboxesResource', self::at($document, 'components', 'schemas', 'InboxItemsInboxRelatedDocument', 'properties', 'data', 'anyOf', '0', '$ref'));
        self::assertNotNull(self::at($document, 'paths', '/inbox-items/{id}/inbox', 'parameters'));
        // The related resource keeps its id and links to its own endpoint without one.
        self::assertSame('42', self::at($response, 'example', 'data', 'id'));
        self::assertSame('https://api.example.com/inbox', self::at($response, 'example', 'data', 'links', 'self', 'href'));
        self::assertSame('https://api.example.com/inbox-items/item-1/inbox', self::at($response, 'example', 'links', 'self', 'href'));
    }

    #[Test]
    public function itsResourceComponentNamesItsPath(): void
    {
        $schemas = self::arr(self::at($this->project(), 'components', 'schemas'));

        self::assertSame('/inbox', self::at($schemas, 'InboxesResource', 'x-singleton'));
        self::assertNull(self::at($schemas, 'InboxItemsResource', 'x-singleton'));
    }

    #[Test]
    public function itsExamplesLinkToItsPathsWithoutAnId(): void
    {
        $document = $this->project();
        $example = self::arr(self::at($document, 'paths', '/inbox', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example'));

        self::assertSame('42', self::at($example, 'data', 'id'));
        self::assertSame(['href' => 'https://api.example.com/inbox', 'describedby' => '/docs/openapi.json#/paths/~1inbox/get'], self::at($example, 'data', 'links', 'self'));
        self::assertSame(['href' => 'https://api.example.com/inbox/-actions/mark-all-read', 'describedby' => '/docs/openapi.json#/paths/~1inbox~1-actions~1mark-all-read/post'], self::at($example, 'data', 'links', 'mark-all-read'));
        self::assertSame([
            'self' => ['href' => 'https://api.example.com/inbox/relationships/owner', 'describedby' => '/docs/openapi.json#/paths/~1inbox~1relationships~1owner/get'],
            'related' => ['href' => 'https://api.example.com/inbox/owner', 'describedby' => '/docs/openapi.json#/paths/~1inbox~1owner/get'],
        ], self::at($example, 'data', 'relationships', 'owner', 'links'));
        self::assertSame(['href' => 'https://api.example.com/inbox', 'describedby' => '/docs/openapi.json#/paths/~1inbox/get'], self::at($example, 'links', 'self'));

        $relationship = self::arr(self::at($document, 'paths', '/inbox/relationships/owner', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example'));
        self::assertSame('https://api.example.com/inbox/relationships/owner', self::at($relationship, 'links', 'self', 'href'));

        $update = self::arr(self::at($document, 'paths', '/inbox', 'patch', 'responses', '200', 'content', 'application/vnd.api+json', 'example'));
        self::assertSame('/docs/openapi.json#/paths/~1inbox/patch', self::at($update, 'links', 'describedby', 'href'));
    }

    #[Test]
    public function theLinkObjectExampleUsesItsPathWhenItIsTheFirstType(): void
    {
        $examples = self::at($this->project(inboxFirst: true), 'components', 'schemas', 'LinkObject', 'examples');

        self::assertSame([['href' => 'https://api.example.com/inbox', 'describedby' => '/docs/openapi.json#/paths/~1inbox/get']], $examples);
    }

    #[Test]
    public function itsErrorDocumentsAreNamedForItsOperations(): void
    {
        $document = $this->project();
        $schemas = self::arr(self::at($document, 'components', 'schemas'));

        self::assertArrayHasKey('InboxesFetchOne404', $schemas);
        self::assertArrayHasKey('InboxesUpdate409', $schemas);
        self::assertArrayHasKey('InboxesMarkAllReadAction404', $schemas);
        self::assertSame('#/components/schemas/InboxesUpdate409', self::at($document, 'paths', '/inbox', 'patch', 'responses', '409', 'content', 'application/vnd.api+json', 'schema', '$ref'));
    }

    #[Test]
    public function itsScopesAndNamedExtensionsFollowItsPaths(): void
    {
        $document = $this->project(scoped: true);

        self::assertSame([['oauth2' => ['inbox:read']]], self::at($document, 'paths', '/inbox', 'get', 'security'));
        self::assertSame([['oauth2' => ['inbox:write']]], self::at($document, 'paths', '/inbox', 'patch', 'security'));
        self::assertSame(['tool' => 'read_my_inbox'], self::at($document, 'paths', '/inbox', 'get', 'x-mcp'));
        self::assertSame(false, self::at($document, 'paths', '/inbox/relationships/owner', 'patch', 'x-mcp'));
    }

    #[Test]
    public function aDocumentWithASingletonCarriesItsContract(): void
    {
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SINGLETONS)], self::at($this->project(), 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($this->project(singleton: false), 'info', 'x-generator'));
    }

    #[Test]
    public function aTypeThatIsNotASingletonKeepsItsIdPaths(): void
    {
        $paths = \array_keys(self::arr($this->project(singleton: false)['paths']));

        self::assertContains('/inbox/{id}', $paths);
        self::assertContains('/inbox/{id}/relationships/owner', $paths);
        self::assertNotContains('/inbox/relationships/owner', $paths);
    }

    /**
     * @param list<OperationType> $operations
     */
    #[Test]
    #[DataProvider('operationsASingletonCannotServe')]
    public function aSingletonExposingACollectionCreateOrDeleteIsRefused(array $operations, string $named): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage(\sprintf('Type "inboxes" is a singleton, so it serves only fetch and update, but it declares %s. Declare a second type over the same entity for those operations; an entity can back several types.', $named));

        $this->project(operations: $operations);
    }

    /**
     * @return iterable<string, array{list<OperationType>, string}>
     */
    public static function operationsASingletonCannotServe(): iterable
    {
        yield 'collection' => [[OperationType::FetchCollection, OperationType::FetchOne], 'FetchCollection'];
        yield 'create' => [[OperationType::FetchOne, OperationType::Create], 'Create'];
        yield 'delete' => [[OperationType::FetchOne, OperationType::Delete], 'Delete'];
        yield 'all three' => [[OperationType::FetchCollection, OperationType::Create, OperationType::Delete], 'FetchCollection, Create, Delete'];
    }

    #[Test]
    public function aSingletonWithACollectionActionIsRefused(): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('Type "inboxes" is a singleton, so every action on it is resource-scoped, but it declares the collection-scope action "purge".');

        $this->project(collectionAction: true);
    }

    #[Test]
    public function aReadOnlySingletonServesFetchOnly(): void
    {
        $document = $this->project(operations: [OperationType::FetchOne]);

        self::assertSame(['get'], \array_keys(self::arr(self::at($document, 'paths', '/inbox'))));
        self::assertNull(self::at($document, 'components', 'schemas', 'InboxesUpdateRequest'));
        // The relationship endpoint is read-only too, as the update allow-list gates mutations.
        self::assertSame(['get'], \array_keys(self::arr(self::at($document, 'paths', '/inbox/relationships/owner'))));
    }

    /**
     * @param list<OperationType> $operations
     *
     * @return array<string, mixed>
     */
    private function project(bool $singleton = true, array $operations = [OperationType::FetchOne, OperationType::Update], bool $collectionAction = false, bool $scoped = false, bool $inboxFirst = false): array
    {
        $inboxes = FakeTypeMetadata::resource(
            type: 'inboxes',
            fields: [
                Id::make()->example('42')->build(),
                Integer::make('unseen')->readOnly()->example(3)->build(),
                Integer::make('unread')->readOnly()->example(5)->build(),
                DateTime::make('seenUpTo')->example('2026-11-16T09:30:00+00:00')->build(),
            ],
            relations: [new FakeRelationMetadata('owner', ['people'], false, exampleId: '7')],
            uriType: 'inbox',
            operations: $operations,
            actions: [
                new FakeActionMetadata('mark-all-read', ['POST'], ActionScope::Resource, ActionInputMode::None, outputType: 'inboxes'),
                ...($collectionAction ? [new FakeActionMetadata('purge', ['POST'], ActionScope::Collection)] : []),
            ],
            actionLinks: [ActionLink::for('mark-all-read')->build()],
            singleton: $singleton,
        );
        $items = FakeTypeMetadata::resource(
            type: 'inbox-items',
            fields: [Id::make()->example('item-1')->build(), Str::make('title')->example('New follower')->build()],
            relations: [new FakeRelationMetadata('inbox', ['inboxes'], false, exampleId: '42')],
            includablePaths: ['inbox'],
        );
        $people = FakeTypeMetadata::resource(
            type: 'people',
            fields: [Id::make()->example('7')->build(), Str::make('name')->example('Ada')->build()],
        );

        $types = $inboxFirst ? [$inboxes, $items, $people] : [$items, $people, $inboxes];
        if ($scoped) {
            $types = \array_map(static fn(TypeMetadataInterface $type): TypeMetadataInterface => $type->type() === 'inboxes'
                ? new SingletonNamedExtensions($type, ['fetchOne' => ['mcp' => ['tool' => 'read_my_inbox']], 'updateRelationship.owner' => ['mcp' => false]])
                : $type, $types);
        }

        $server = new FakeServerMetadata(
            title: 'Singleton API',
            version: '1.0.0',
            types: $types,
            servers: [new Server('https://api.example.com')],
            securitySchemes: $scoped ? ['oauth2' => SecurityScheme::oauth2(new OAuthFlows(clientCredentials: new OAuthFlow([], tokenUrl: 'https://auth.example.com/token')))] : [],
            defaultSecurity: $scoped ? [SecurityRequirement::scheme('oauth2')] : [],
            generatesExamples: true,
        );
        $server = new LinkDeclaringServerMetadata($server, documentUrl: '/docs/openapi.json');
        if ($scoped) {
            $server = self::scoped($server);
        }

        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project($server)->toArray();
    }

    private static function scoped(ServerMetadataInterface $server): ServerMetadataInterface
    {
        $resolver = new class implements OperationScopeResolverInterface {
            public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
            {
                if ($operation->type !== 'inboxes') {
                    return [];
                }

                return $operation->kind === OperationKind::FetchOne ? ['inbox:read'] : ['inbox:write'];
            }
        };

        return new ScopeDeclaringServerMetadata($server, (new ScopeTableBuilder($server, $resolver))->build());
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }
}

final readonly class SingletonNamedExtensions extends DelegatingTypeMetadata implements DeclaresNamedOperationExtensions, DeclaresSingleton
{
    /**
     * @param array<string, array<string, mixed>> $named
     */
    public function __construct(TypeMetadataInterface $inner, private array $named)
    {
        parent::__construct($inner);
    }

    public function namedOperationExtensions(): array
    {
        return $this->named;
    }

    public function isSingleton(): bool
    {
        return $this->inner instanceof DeclaresSingleton && $this->inner->isSingleton();
    }
}
