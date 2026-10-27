<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Catalog;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\OperationSecurity;
use haddowg\JsonApi\Catalog\OperationSecuritySourceInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeDeclaringServerMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationCatalog::class)]
#[CoversClass(OperationDefinition::class)]
#[CoversClass(OperationSecurity::class)]
final class OperationCatalogTest extends TestCase
{
    #[Test]
    public function itDescribesEveryOperationInDocumentOrder(): void
    {
        $definitions = $this->list($this->catalog($this->server())->forServer('default'));

        self::assertSame([
            'fetchCollection.articles',
            'fetchOne.articles',
            'update.articles',
            'fetchRelated.articles.author',
            'fetchRelationship.articles.author',
            'updateRelationship.articles.author',
            'action.articles.archive',
            'atomic.operations',
        ], \array_map(static fn(OperationDefinition $definition): string => $definition->operationId, $definitions));
    }

    #[Test]
    public function aDefinitionCarriesTheOperationsRouteAndIdentity(): void
    {
        [, , , $related, , , $action, $atomic] = $this->list($this->catalog($this->server())->forServer('default'));

        self::assertSame('default', $related->server);
        self::assertSame('articles', $related->type);
        self::assertSame(OperationKind::FetchRelated, $related->kind);
        self::assertSame('GET', $related->method);
        self::assertSame('/articles/{id}/author', $related->pathTemplate);
        self::assertSame('author', $related->relation);
        self::assertSame(['Articles'], $related->tags);
        self::assertSame('articles', $related->key->type);
        self::assertSame('fetchRelated.author', $related->key->name());
        self::assertNull($related->safety);

        self::assertSame('POST', $action->method);
        self::assertSame('archive', $action->actionPath);
        self::assertSame(ActionScope::Resource, $action->actionScope);
        self::assertSame('/articles/{id}/-actions/archive', $action->pathTemplate);
        self::assertSame(['Publishing'], $action->tags);
        self::assertSame(['destructive' => true], $action->safety?->toArray());

        self::assertNull($atomic->type);
        self::assertSame(OperationKind::Atomic, $atomic->kind);
        self::assertSame('/operations', $atomic->pathTemplate);
        self::assertSame(['Atomic Operations'], $atomic->tags);
    }

    #[Test]
    public function theSecuritySourceSuppliesEachRule(): void
    {
        $source = new RecordingSecuritySource();
        [$collection, $one, $update] = $this->list($this->catalog($this->server(), $source)->forServer('admin'));

        self::assertEquals(OperationSecurity::none(), $source->rules['fetchCollection'] ?? null);
        self::assertNull($collection->security);
        self::assertSame(SecurityLevel::None, $collection->securityLevel);
        self::assertSame("is_granted('ROLE_USER')", $one->security);
        self::assertSame(SecurityLevel::Type, $one->securityLevel);
        self::assertSame('is_granted(\'EDIT\', object)', $update->security);
        self::assertSame(SecurityLevel::Entity, $update->securityLevel);
        self::assertSame(['admin'], \array_values(\array_unique($source->servers)));
        self::assertSame('articles', $source->types['update']);
        self::assertNull($source->types['atomic']);
    }

    #[Test]
    public function theRequiredScopesAreTheEffectiveOnesFromTheTable(): void
    {
        $server = $this->server();
        $scoped = new ScopeDeclaringServerMetadata($server, (new ScopeTableBuilder($server, new ScopesByType()))->build());

        $scopes = [];
        foreach ($this->catalog($scoped)->forServer('default') as $definition) {
            $scopes[$definition->operationId] = $definition->requiredScopes;
        }

        self::assertSame(['articles:read', 'people:read'], $scopes['fetchRelated.articles.author']);
        self::assertSame(['articles:write'], $scopes['update.articles']);
        self::assertSame(['batch'], $scopes['atomic.operations']);
    }

    #[Test]
    public function withoutAScopeTableNothingIsRequired(): void
    {
        foreach ($this->catalog($this->server())->forServer('default') as $definition) {
            self::assertSame([], $definition->requiredScopes, $definition->operationId);
        }

        $empty = new ScopeDeclaringServerMetadata($this->server(), new ScopeTable());
        foreach ($this->catalog($empty)->forServer('default') as $definition) {
            self::assertSame([], $definition->requiredScopes, $definition->operationId);
        }
    }

    #[Test]
    public function aTableMissingAnOperationThrows(): void
    {
        $scoped = new ScopeDeclaringServerMetadata($this->server(), new ScopeTable(['articles' => ['fetchCollection' => []]]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The scope table has no entry for the operation "fetchOne" of type "articles".');

        foreach ($this->catalog($scoped)->forServer('default') as $definition) {
            self::assertSame([], $definition->requiredScopes);
        }
    }

    #[Test]
    public function theMetadataIsReadOnlyWhenTheCatalogueIsIterated(): void
    {
        $calls = 0;
        $catalog = new OperationCatalog(function (string $name) use (&$calls): ServerMetadataInterface {
            ++$calls;

            return $this->server();
        }, new RecordingSecuritySource());

        $operations = $catalog->forServer('default');
        self::assertSame(0, $calls);

        $operations->current();
        self::assertSame(1, $calls);
    }

    #[Test]
    public function itListsExactlyTheOperationsTheDocumentProjects(): void
    {
        $server = ContractWitnessServer::build();
        $projected = [];
        $paths = (new OpenApiProjector())->project($server)->paths;
        self::assertNotNull($paths);
        foreach ($paths->items as $path => $item) {
            foreach ($item->operations as $method => $operation) {
                $projected[] = [$operation->operationId, $path, \strtoupper($method)];
            }
        }

        $catalogued = \array_map(
            static fn(OperationDefinition $definition): array => [$definition->operationId, $definition->pathTemplate, $definition->method],
            $this->list($this->catalog($server)->forServer('default')),
        );

        \sort($projected);
        \sort($catalogued);
        self::assertSame($projected, $catalogued);
    }

    #[Test]
    public function anActionAnsweringWithAUrlIsCataloguedLikeAnyOtherAction(): void
    {
        $definitions = $this->list($this->catalog(ContractWitnessServer::build())->forServer('default'));
        $download = null;
        foreach ($definitions as $definition) {
            if ($definition->operationId === 'action.articles.download') {
                $download = $definition;
            }
        }

        self::assertNotNull($download);
        self::assertSame('GET', $download->method);
        self::assertSame('/articles/{id}/-actions/download', $download->pathTemplate);
        self::assertSame(ActionScope::Resource, $download->actionScope);
        self::assertSame(['readOnly' => true], $download->safety?->toArray());
    }

    private function catalog(ServerMetadataInterface $server, ?OperationSecuritySourceInterface $source = null): OperationCatalog
    {
        return new OperationCatalog(static fn(string $name): ServerMetadataInterface => $server, $source ?? new RecordingSecuritySource());
    }

    /**
     * @param iterable<OperationDefinition> $definitions
     *
     * @return list<OperationDefinition>
     */
    private function list(iterable $definitions): array
    {
        $list = [];
        foreach ($definitions as $definition) {
            $list[] = $definition;
        }

        return $list;
    }

    private function server(): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1',
            types: [
                FakeTypeMetadata::resource(
                    type: 'articles',
                    fields: [Id::make()->build()],
                    relations: [new FakeRelationMetadata('author', ['people'], false, allowsReplace: true)],
                    tags: ['Articles'],
                    operations: [OperationType::FetchCollection, OperationType::FetchOne, OperationType::Update],
                    actions: [new FakeActionMetadata('archive', tags: ['Publishing'], destructive: true)],
                ),
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build()], operations: []),
            ],
            atomicOperations: new FakeAtomicOperationsMetadata(),
        );
    }
}

final class RecordingSecuritySource implements OperationSecuritySourceInterface
{
    /** @var list<string> */
    public array $servers = [];

    /** @var array<string, string|null> */
    public array $types = [];

    /** @var array<string, OperationSecurity> */
    public array $rules = [];

    public function securityOf(string $server, ?TypeMetadataInterface $type, OperationKey $key): OperationSecurity
    {
        $this->servers[] = $server;
        $this->types[$key->name()] = $type?->type();

        return $this->rules[$key->name()] = match ($key->kind) {
            OperationKind::FetchOne => new OperationSecurity("is_granted('ROLE_USER')", SecurityLevel::Type),
            OperationKind::Update => new OperationSecurity("is_granted('EDIT', object)", SecurityLevel::Entity),
            default => OperationSecurity::none(),
        };
    }
}

final class ScopesByType implements OperationScopeResolverInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        return match (true) {
            $operation->kind === OperationKind::Atomic => ['batch'],
            $operation->kind === OperationKind::Action => [],
            $operation->method === 'GET' => [$operation->type . ':read'],
            default => [$operation->type . ':write'],
        };
    }
}
