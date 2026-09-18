<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Tests\OpenApi\Fixture\CommaListFilter;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `filter[<key>]` value schema: the container shape each filter kind projects, and
 * the field-derived type that fills it in when the author declared no value constraints
 * (ADR 0138).
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(SchemaProjector::class)]
final class FilterValueSchemaProjectionTest extends TestCase
{
    /**
     * @return iterable<string, array{FilterInterface, array<string, mixed>}>
     */
    public static function valueSchemas(): iterable
    {
        yield 'a string column' => [Where::make('title')->build(), ['schema' => ['type' => 'string']]];
        yield 'an integer column' => [Where::make('views')->build(), ['schema' => ['type' => 'integer']]];
        yield 'a decimal column' => [Where::make('score')->build(), ['schema' => ['type' => 'number']]];
        yield 'a boolean column' => [Where::make('featured')->build(), ['schema' => ['type' => 'boolean']]];
        yield 'an explicit column, not the key' => [
            Where::make('published', 'published_at')->build(),
            ['schema' => ['type' => 'string']],
        ];

        // The fallback types the value and stops. A format, an enum or a length belongs
        // to the field in a document body; the filter's operator decides what its own
        // operand may be, and the fallback models no operator.
        yield 'a date-time column carries no format' => [
            Where::make('published', 'published_at')->build(),
            ['schema' => ['type' => 'string']],
        ];
        yield 'an enum column carries no enum' => [Where::make('status')->build(), ['schema' => ['type' => 'string']]];
        yield 'a substring match over an enum column is still a plain string' => [
            Contains::make('status')->build(),
            ['schema' => ['type' => 'string']],
        ];

        // A filter that declared anything keeps exactly what it declared — including a
        // `format` with no `type` beside it, and including a type its column contradicts.
        yield 'a declared type wins over the column type' => [
            Where::make('title')->integer()->build(),
            ['schema' => ['type' => 'integer']],
        ];
        yield 'a constraint that types nothing still suppresses the fallback' => [
            Where::make('views')->uuid()->build(),
            ['schema' => ['format' => 'uuid']],
        ];

        // Nothing resolves the column, so nothing is claimed about the value.
        yield 'a column no field backs' => [Where::make('unknown')->build(), ['schema' => []]];
        yield 'a computed field backs no column' => [Where::make('displayTitle')->build(), ['schema' => []]];
        yield 'a flattened related attribute backs no column here' => [
            Where::make('authorName')->build(),
            ['schema' => []],
        ];
        yield 'two fields share the column' => [Where::make('shared')->build(), ['schema' => []]];
        yield 'a relation is not a value' => [Where::make('author')->build(), ['schema' => []]];
        yield 'a list column has no scalar form' => [Where::make('keywords')->build(), ['schema' => []]];
        yield 'an open-object column has no scalar form' => [Where::make('analytics')->build(), ['schema' => []]];
        yield 'a map column has no scalar form' => [Where::make('address')->build(), ['schema' => []]];
        yield 'a self-describing composite has no scalar form' => [Where::make('block')->build(), ['schema' => []]];
        yield 'a relationship path is not a column' => [
            WhereThrough::make('author.name')->build(),
            ['schema' => []],
        ];
        yield 'a group spans columns' => [
            WhereAny::make('q', Contains::make('title'), Contains::make('status'))->build(),
            ['schema' => []],
        ];
        yield 'a consumer filter naming no column' => [new CommaListFilter('labels'), [
            'schema' => ['type' => 'array', 'items' => []],
            'style' => 'form',
            'explode' => false,
        ]];

        // Presence-only: the value is read by nobody, so it is a string whatever the
        // column would have said.
        yield 'a null test' => [WhereNull::make('published', 'published_at'), ['schema' => ['type' => 'string']]];
        yield 'a not-null test' => [WhereNotNull::make('views'), ['schema' => ['type' => 'string']]];
        yield 'a relationship-existence test' => [WhereHas::make('author'), ['schema' => ['type' => 'string']]];
        yield 'a relationship-absence test' => [WhereDoesntHave::make('author'), ['schema' => ['type' => 'string']]];
        yield 'a fixed value over a typed column' => [
            Where::make('views')->fixed(10)->build(),
            ['schema' => ['type' => 'string']],
        ];
        yield 'an all-fixed group' => [
            WhereAny::make('urgent', GreaterThan::make('views')->fixed(10))->build(),
            ['schema' => ['type' => 'string']],
        ];

        // A set is a list whatever its element type turns out to be.
        yield 'a set over a string column' => [WhereIn::make('title')->build(), [
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            'style' => 'form',
            'explode' => false,
        ]];
        yield 'an excluded set over an integer column' => [WhereNotIn::make('views')->build(), [
            'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'style' => 'form',
            'explode' => false,
        ]];
        yield 'an id set' => [WhereIdIn::make()->build(), [
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            'style' => 'form',
            'explode' => false,
        ]];
        yield 'an excluded id set whose elements are constrained' => [WhereIdNotIn::make('notId')->integer()->build(), [
            'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'style' => 'form',
            'explode' => false,
        ]];
        yield 'a set over a column with no scalar form' => [WhereIn::make('keywords')->build(), [
            'schema' => ['type' => 'array', 'items' => []],
            'style' => 'form',
            'explode' => false,
        ]];
        yield 'a pipe-delimited set' => [WhereIn::make('views')->delimiter('|')->build(), [
            'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'style' => 'pipeDelimited',
            'explode' => false,
        ]];
        yield 'a space-delimited set' => [WhereIn::make('title')->delimiter(' ')->build(), [
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            'style' => 'spaceDelimited',
            'explode' => false,
        ]];
        yield 'a set on a delimiter OAS cannot spell' => [
            WhereIn::make('title')->delimiter(';')->build(),
            ['schema' => ['type' => 'string']],
        ];

        // A range presets its own numeric bound constraint, so it needs no fallback and
        // never gets one; the container/value split is the same one every kind uses.
        yield 'a range declares its own bounds' => [Range::make('score')->build(), [
            'schema' => [
                'type' => 'object',
                'properties' => ['min' => ['type' => 'number'], 'max' => ['type' => 'number']],
            ],
            'style' => 'deepObject',
            'explode' => true,
        ]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('valueSchemas')]
    public function aFilterProjectsItsValueSchema(FilterInterface $filter, array $expected): void
    {
        self::assertSame($expected, $this->filterParameter($filter));
    }

    #[Test]
    public function aRelationScopedFilterResolvesAgainstTheRelatedTypeInventory(): void
    {
        $server = new FakeServerMetadata(
            title: 'Widgets',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'widgets',
                    fields: self::inventory(),
                    relations: [new FakeRelationMetadata('author', ['people'], false, filters: [Where::make('views')->build()])],
                ),
                FakeTypeMetadata::resource(
                    type: 'people',
                    fields: [Id::make()->build(), Str::make('views')->build()],
                ),
            ],
        );

        $document = (new OpenApiProjector())->project($server)->toArray();

        // `views` is an integer on the parent and a string on the related type, so only
        // a filter resolved against the RELATED inventory reads as a string here. The
        // endpoint filters the related rows, and those are the rows that have the column.
        self::assertSame(
            ['type' => 'string'],
            $this->schemaOf($document, '/widgets/{id}/author', 'filter[views]'),
        );
    }

    #[Test]
    public function aPolymorphicRelationHasNoInventoryToResolveAgainst(): void
    {
        $server = new FakeServerMetadata(
            title: 'Widgets',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'widgets',
                    fields: self::inventory(),
                    relations: [new FakeRelationMetadata(
                        'attachments',
                        ['people', 'places'],
                        true,
                        filters: [Where::make('views')->build()],
                    )],
                ),
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Integer::make('views')->build()]),
                FakeTypeMetadata::resource(type: 'places', fields: [Id::make()->build(), Integer::make('views')->build()]),
            ],
        );

        $document = (new OpenApiProjector())->project($server)->toArray();

        self::assertSame([], $this->schemaOf($document, '/widgets/{id}/attachments', 'filter[views]'));
    }

    /**
     * The one inventory every case resolves against: a field of each JSON type, plus the
     * shapes that deliberately resolve to nothing.
     *
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    private static function inventory(): array
    {
        return [
            Id::make()->build(),
            Str::make('title')->build(),
            Integer::make('views')->build(),
            Decimal::make('score')->build(),
            Boolean::make('featured')->build(),
            DateTime::make('published')->storedAs('published_at')->build(),
            Date::make('signedOn')->build(),
            Url::make('canonical')->build(),
            Str::make('status')->enum(Status::class)->build(),
            ArrayList::make('keywords')->build(),
            ArrayHash::make('analytics')->build(),
            Map::make('address')->fields(Str::make('street')->build())->build(),
            OneOf::make('block')->discriminator('kind')
                ->variant('heading', Str::make('text')->build())
                ->build(),
            Str::make('displayTitle')->computed()->extractUsing(static fn(mixed $model): string => '')->build(),
            Str::make('authorName')->on('author')->build(),
            Str::make('alpha')->storedAs('shared')->build(),
            Str::make('beta')->storedAs('shared')->build(),
            BelongsTo::make('author', 'people')->build(),
        ];
    }

    /**
     * The projected `filter[<key>]` parameter of `GET /widgets`, without its
     * description (asserted elsewhere) or the members every query parameter carries.
     *
     * @return array<string, mixed>
     */
    private function filterParameter(FilterInterface $filter): array
    {
        $server = new FakeServerMetadata(
            title: 'Widgets',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'widgets', fields: self::inventory(), filters: [$filter]),
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()]),
            ],
        );

        $parameter = $this->parameter(
            (new OpenApiProjector())->project($server)->toArray(),
            '/widgets',
            'filter[' . $filter->key() . ']',
        );

        return \array_intersect_key($parameter, ['schema' => true, 'style' => true, 'explode' => true]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function schemaOf(array $document, string $path, string $name): array
    {
        $schema = $this->parameter($document, $path, $name)['schema'] ?? null;
        self::assertIsArray($schema);

        return $schema;
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
        self::assertIsArray($item);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation);
        $parameters = $operation['parameters'] ?? null;
        self::assertIsArray($parameters);

        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail("No {$name} parameter on GET {$path}.");
    }
}
