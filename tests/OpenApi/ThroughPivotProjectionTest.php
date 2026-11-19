<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation's filter whose path starts with `pivot` is resolved under that relation on its
 * related and relationship endpoints, so it is typed by the pivot field it reads.
 */
#[CoversClass(OperationProjector::class)]
final class ThroughPivotProjectionTest extends TestCase
{
    #[Test]
    public function aLeadingPivotFilterIsTypedByThePivotField(): void
    {
        $document = $this->document(Where::make('position', 'position')->through('pivot')->build());

        foreach (['/playlists/{id}/tracks', '/playlists/{id}/relationships/tracks'] as $path) {
            $parameter = $this->parameter($document, $path, 'filter[position]');
            self::assertSame(['type' => 'integer'], $parameter['schema']);
            self::assertSame('Filter the collection by `position`, on the pivot row.', $parameter['description']);
        }
    }

    #[Test]
    public function aLeadingPivotDateRangeReadsTheDateTimePivotField(): void
    {
        $document = $this->document(DateRange::make('addedAt', 'addedAt')->through('pivot')->build());

        self::assertSame(
            ['type' => 'object', 'properties' => ['min' => ['type' => 'string', 'format' => 'date-time'], 'max' => ['type' => 'string', 'format' => 'date-time']]],
            $this->parameter($document, '/playlists/{id}/tracks', 'filter[addedAt]')['schema'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(FilterInterface $filter): array
    {
        $relation = BelongsToMany::make('tracks', 'tracks')
            ->fields(Integer::make('position'), DateTime::make('addedAt')->readOnly())
            ->withFilters($filter)
            ->build();
        $server = new FakeServerMetadata(
            title: 'Playlists',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'playlists',
                    fields: [Id::make()->build(), $relation],
                    relations: [new FakeRelationMetadata('tracks', ['tracks'], true, filters: [$filter], pivotFields: $relation->pivotFields())],
                ),
                FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make()->build(), Str::make('title')->build()]),
            ],
        );

        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function parameter(array $document, string $path, string $name): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item, $path);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation);
        $parameters = $operation['parameters'] ?? null;
        self::assertIsArray($parameters);
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                /** @var array<string, mixed> $parameter */
                return $parameter;
            }
        }

        self::fail("No {$name} parameter on GET {$path}.");
    }
}
