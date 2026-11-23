<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation's filter whose path starts with `pivot` is resolved under that relation on its
 * related and relationship endpoints, so it is typed by the pivot field it reads. A path
 * that pivots on a type's first relation and goes on past the pivot row is typed by the
 * field of the type the pivot row reaches.
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
            ['type' => 'object', 'properties' => ['min' => ['type' => 'string', 'format' => 'date-time'], 'max' => ['type' => 'string', 'format' => 'date-time']], 'additionalProperties' => false],
            $this->parameter($document, '/playlists/{id}/tracks', 'filter[addedAt]')['schema'],
        );
    }

    #[Test]
    public function aCollectionFilterPivotingOnItsFirstRelationIsTypedByTheTypeThePivotRowReaches(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position'))->build();
        $filter = Where::make('curator', 'name')->through('tracks.pivot.addedBy')->build();
        $rows = new class implements PivotRowRelations {
            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                return $parentType === 'playlists' && $member === 'addedBy' ? BelongsTo::make('addedBy', 'users')->build() : null;
            }
        };
        $server = new FakeServerMetadata(
            title: 'Playlists',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'playlists',
                    fields: [Id::make()->build(), $relation],
                    filters: [$filter],
                    relations: [new FakeRelationMetadata('tracks', ['tracks'], true, pivotFields: $relation->pivotFields())],
                ),
                FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make()->build(), Str::make('title')->build()]),
                FakeTypeMetadata::resource(type: 'users', fields: [Id::make()->build(), Integer::make('name')->build()]),
            ],
            pivotRows: $rows,
        );

        $document = (new OpenApiProjector())->project($server)->toArray();

        self::assertSame(['type' => 'integer'], $this->parameter($document, '/playlists', 'filter[curator]')['schema']);
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
