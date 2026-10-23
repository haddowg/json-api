<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ExtendedTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Type metadata implementing {@see DeclaresOperationExtensions} gets its vendor
 * extensions stamped on the operations the projector emits for it.
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
