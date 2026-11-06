<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresNamedOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\DelegatingTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ExtendedTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\NamedExtensionsTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Type metadata implementing {@see DeclaresOperationExtensions} or
 * {@see DeclaresNamedOperationExtensions} gets its vendor extensions stamped on the
 * operations the projector emits for it.
 */
#[CoversClass(OperationProjector::class)]
final class OperationExtensionsProjectionTest extends TestCase
{
    #[Test]
    public function theTypeWideExtensionsReachEveryOperationOfTheType(): void
    {
        $paths = $this->paths(ExtendedTypeMetadata::featureGatedServer());

        $operations = [
            ['/articles', 'get'],
            ['/articles/{id}', 'get'],
            ['/articles/{id}', 'patch'],
            ['/articles/{id}/author', 'get'],
            ['/articles/{id}/relationships/author', 'get'],
            ['/articles/{id}/relationships/author', 'patch'],
            ['/articles/{id}/-actions/publish', 'post'],
        ];
        foreach ($operations as [$path, $method]) {
            self::assertSame('agency', $paths[$path][$method]['x-feature'] ?? null, \sprintf('%s %s', $method, $path));
        }
    }

    #[Test]
    public function anOperationsOwnExtensionsMergeOverTheTypeWideOnes(): void
    {
        $paths = $this->paths(ExtendedTypeMetadata::featureGatedServer());

        self::assertSame('agency-write', $paths['/articles']['post']['x-feature']);
        self::assertSame('agency', $paths['/articles/{id}']['delete']['x-feature']);
        self::assertSame(['level' => 'high'], $paths['/articles/{id}']['delete']['x-audit']);
        self::assertArrayNotHasKey('x-audit', $paths['/articles/{id}']['get']);
    }

    #[Test]
    public function aTypeThatDeclaresNoExtensionsIsUntouched(): void
    {
        $paths = $this->paths(ExtendedTypeMetadata::featureGatedServer());

        foreach (['/people', '/people/{id}'] as $path) {
            foreach ($paths[$path] as $member => $operation) {
                if (\is_array($operation)) {
                    self::assertSame([], \preg_grep('/^x-/', \array_keys($operation)), \sprintf('%s %s', $member, $path));
                }
            }
        }
    }

    #[Test]
    public function aRelatedEndpointGetsTheSetDeclaredUnderItsName(): void
    {
        $paths = $this->paths(ExtendedTypeMetadata::featureGatedServer([
            'fetchRelated.author' => ['team' => 'people'],
        ]));

        self::assertSame('people', $paths['/articles/{id}/author']['get']['x-team']);
        self::assertSame('agency', $paths['/articles/{id}/author']['get']['x-feature']);
        foreach ([['/articles/{id}/relationships/author', 'get'], ['/articles/{id}', 'get'], ['/articles/{id}/-actions/publish', 'post']] as [$path, $method]) {
            self::assertArrayNotHasKey('x-team', $paths[$path][$method], \sprintf('%s %s', $method, $path));
        }
    }

    #[Test]
    public function theNamedSetWinsOverTheTypeWideAndCrudSets(): void
    {
        $paths = $this->paths(ExtendedTypeMetadata::featureGatedServer([
            'create' => ['x-feature' => 'agency-create'],
            'delete' => ['audit' => ['level' => 'critical']],
            'fetchRelationship.author' => ['x-feature' => 'agency-linkage'],
        ]));

        self::assertSame('agency-create', $paths['/articles']['post']['x-feature']);
        self::assertSame(['level' => 'critical'], $paths['/articles/{id}']['delete']['x-audit']);
        self::assertSame('agency', $paths['/articles/{id}']['delete']['x-feature']);
        self::assertSame('agency-linkage', $paths['/articles/{id}/relationships/author']['get']['x-feature']);
        self::assertSame('agency', $paths['/articles/{id}/relationships/author']['patch']['x-feature']);
    }

    #[Test]
    public function anActionsOwnSetStillWins(): void
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build()],
            relations: [FakeRelationMetadata::toOne('author', ['articles'])],
            actions: [new FakeActionMetadata('publish', extensions: ['feature' => 'publishing'])],
        );
        $server = new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [new ExtendedTypeMetadata(
            $articles,
            extensions: ['feature' => 'agency'],
            namedOperationExtensions: ['fetchRelated.author' => ['feature' => 'agency-related']],
        )]);

        $paths = $this->paths($server);

        self::assertSame('publishing', $paths['/articles/{id}/-actions/publish']['post']['x-feature']);
        self::assertSame('agency-related', $paths['/articles/{id}/author']['get']['x-feature']);
    }

    #[Test]
    public function theNamedSetNeedsNoTypeWideDeclaration(): void
    {
        $server = new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [
            new NamedExtensionsTypeMetadata(ExtendedTypeMetadata::articles(), ['fetchRelated.author' => ['feature' => 'agency']]),
            FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build()]),
        ]);

        $paths = $this->paths($server);

        self::assertSame('agency', $paths['/articles/{id}/author']['get']['x-feature']);
        self::assertArrayNotHasKey('x-feature', $paths['/articles/{id}']['get']);
    }

    #[Test]
    public function aTypeWithoutTheNamedDeclarationRendersAsBefore(): void
    {
        $crudOnly = new readonly class (ExtendedTypeMetadata::articles()) extends DelegatingTypeMetadata implements DeclaresOperationExtensions {
            public function extensions(): array
            {
                return ['feature' => 'agency'];
            }

            public function operationExtensions(OperationType $operation): array
            {
                return match ($operation) {
                    OperationType::Delete => ['x-audit' => ['level' => 'high']],
                    OperationType::Create => ['feature' => 'agency-write'],
                    default => [],
                };
            }
        };
        $people = FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()], tags: ['People']);

        self::assertSame(
            (new OpenApiProjector())->project(ExtendedTypeMetadata::featureGatedServer())->toArray(),
            (new OpenApiProjector())->project(new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$crudOnly, $people]))->toArray(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesTheTypeDoesNotExpose(): iterable
    {
        yield 'unknown relation' => ['fetchRelated.editor'];
        yield 'not an operation name' => ['fetchSomething'];
        yield 'an action' => ['action.publish.post'];
    }

    #[Test]
    #[DataProvider('namesTheTypeDoesNotExpose')]
    public function aNameTheTypeDoesNotExposeFailsTheProjection(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('declares extensions for the operation "%s"', $name));

        (new OpenApiProjector())->project(ExtendedTypeMetadata::featureGatedServer([$name => ['feature' => 'x']]));
    }

    #[Test]
    public function aReservedExtensionNameFailsTheProjection(): void
    {
        $server = new FakeServerMetadata(
            title: 'Blog API',
            version: '1.0.0',
            types: [new ExtendedTypeMetadata(
                FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->build()]),
                extensions: ['x-oai-feature' => 'agency'],
            )],
        );

        $this->expectException(\InvalidArgumentException::class);
        (new OpenApiProjector())->project($server);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function paths(FakeServerMetadata $server): array
    {
        /** @var array<string, array<string, array<string, mixed>>> */
        return (new OpenApiProjector())->project($server)->toArray()['paths'];
    }
}
