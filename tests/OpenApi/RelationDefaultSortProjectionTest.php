<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation's declared default order is the `default` of the `sort` parameter on its
 * relationship and related endpoints, and nothing else in the document.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(RelationFacts::class)]
final class RelationDefaultSortProjectionTest extends TestCase
{
    #[Test]
    public function bothEndpointsSortParameterDefaultsToTheDeclaredOrder(): void
    {
        $paths = $this->paths(new FakeRelationMetadata('tracks', ['tracks'], true, sorts: [SortByField::make('position')], defaultSort: [
            new SortDirective(SortByField::make('position'), false),
            new SortDirective(SortByField::make('title'), true),
        ]));

        foreach (['/albums/{id}/tracks', '/albums/{id}/relationships/tracks'] as $path) {
            $sort = $this->sortSchema($paths, $path);
            self::assertSame(['position', '-title'], $sort['default'] ?? null, $path);
            self::assertSame('array', $sort['type']);
        }
    }

    #[Test]
    public function aRelationWithoutADefaultProjectsNone(): void
    {
        $paths = $this->paths(new FakeRelationMetadata('tracks', ['tracks'], true, sorts: [SortByField::make('position')]));

        self::assertArrayNotHasKey('default', $this->sortSchema($paths, '/albums/{id}/tracks'));
        self::assertArrayNotHasKey('default', $this->sortSchema($paths, '/albums/{id}/relationships/tracks'));
    }

    #[Test]
    public function theDefaultIsTheOnlyDifference(): void
    {
        $sorts = [SortByField::make('position')];
        $plain = $this->paths(new FakeRelationMetadata('tracks', ['tracks'], true, sorts: $sorts));
        $sorted = $this->paths(new FakeRelationMetadata('tracks', ['tracks'], true, sorts: $sorts, defaultSort: [new SortDirective(SortByField::make('position'), false)]));

        self::assertSame(\json_encode($plain), \json_encode($this->withoutSortDefaults($sorted)));
    }

    /**
     * @param array<array-key, mixed> $paths
     *
     * @return array<array-key, mixed>
     */
    private function withoutSortDefaults(array $paths): array
    {
        foreach (['/albums/{id}/tracks', '/albums/{id}/relationships/tracks'] as $path) {
            $operation = $paths[$path] ?? null;
            self::assertIsArray($operation);
            $get = $operation['get'] ?? null;
            self::assertIsArray($get);
            $parameters = $get['parameters'] ?? null;
            self::assertIsArray($parameters);
            foreach ($parameters as $i => $parameter) {
                self::assertIsArray($parameter);
                if ($parameter['name'] === 'sort') {
                    self::assertIsArray($parameter['schema']);
                    unset($parameter['schema']['default']);
                    $parameters[$i] = $parameter;
                }
            }
            $get['parameters'] = $parameters;
            $operation['get'] = $get;
            $paths[$path] = $operation;
        }

        return $paths;
    }

    /**
     * @param array<array-key, mixed> $paths
     *
     * @return array<array-key, mixed>
     */
    private function sortSchema(array $paths, string $path): array
    {
        $item = $paths[$path] ?? null;
        self::assertIsArray($item);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation);
        self::assertIsArray($operation['parameters'] ?? null);
        foreach ($operation['parameters'] as $parameter) {
            self::assertIsArray($parameter);
            if ($parameter['name'] === 'sort') {
                self::assertIsArray($parameter['schema']);

                return $parameter['schema'];
            }
        }

        self::fail('No sort parameter on ' . $path);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function paths(RelationMetadataInterface $relation): array
    {
        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'albums', fields: [Id::make()->example('alb-1')->build()], relations: [$relation]),
                FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make()->example('trk-1')->build(), Str::make('title')->example('Intro')->build()], sorts: [SortByField::make('title')]),
            ],
        ))->toArray();

        self::assertIsArray($document['paths']);

        return $document['paths'];
    }
}
