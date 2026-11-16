<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `filter[<key>]` parameters of a {@see WhereSet}, a list of booleans in both forms,
 * and of a {@see WhereMorph}, a `deepObject` mirroring a resource identifier.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(WhereSet::class)]
#[CoversClass(WhereMorph::class)]
final class MorphAndSetFilterProjectionTest extends TestCase
{
    #[Test]
    public function aWhereSetIsABooleanListInBothFormsWithItsDefault(): void
    {
        $parameters = $this->parameters(WhereSet::make('archived', 'archivedAt')->default(false)->build());

        self::assertSame([
            'filter[archived]' => [
                'schema' => ['type' => 'array', 'default' => [false], 'items' => ['type' => 'boolean']],
                'style' => 'form',
                'explode' => false,
            ],
            'filter[archived][]' => [
                'schema' => ['type' => 'array', 'default' => [false], 'items' => ['type' => 'boolean']],
                'style' => 'form',
                'explode' => true,
                'x-filter-list' => ['key' => 'archived', 'delimited' => true],
            ],
        ], $parameters);
    }

    #[Test]
    public function aWhereSetCarriesItsDescriptionExampleAndDeprecation(): void
    {
        $document = $this->document(WhereSet::make('archived', 'archivedAt')
            ->describedAs('Whether the item is archived.')
            ->example('true,false')
            ->deprecated()
            ->build());

        $delimited = $this->parameter($document, 'filter[archived]');
        $array = $this->parameter($document, 'filter[archived][]');

        self::assertSame('Whether the item is archived.', $delimited['description']);
        self::assertSame([true, false], $delimited['example']);
        self::assertSame([true, false], $array['example']);
        self::assertTrue($delimited['deprecated']);
        self::assertTrue($array['deprecated']);
    }

    #[Test]
    public function aWhereMorphIsADeepObjectMirroringAResourceIdentifier(): void
    {
        $parameters = $this->parameters(WhereMorph::make('subject')->build());

        self::assertSame([
            'filter[subject]' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['campaigns', 'templates']],
                        'id' => ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]],
                    ],
                    'required' => ['type'],
                    'additionalProperties' => false,
                ],
                'style' => 'deepObject',
                'explode' => true,
            ],
        ], $parameters);
    }

    #[Test]
    public function aWhereMorphCarriesItsDescriptionAndExample(): void
    {
        $parameter = $this->parameter($this->document(WhereMorph::make('about', 'subject')
            ->describedAs('Items about this resource.')
            ->example(['type' => 'campaigns', 'id' => ['4']])
            ->build()), 'filter[about]');

        self::assertSame('Items about this resource.', $parameter['description']);
        self::assertSame(['type' => 'campaigns', 'id' => ['4']], $parameter['example']);
    }

    #[Test]
    public function aWhereMorphOverAMonomorphicRelationFailsTheProjection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not polymorphic');

        $this->document(WhereMorph::make('campaign')->build());
    }

    /**
     * Each `filter[…]` parameter of `GET /leads`, keyed by name, without its description,
     * example, deprecation or the members every query parameter carries.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parameters(FilterInterface $filter): array
    {
        $document = $this->document($filter);
        $parameters = [];
        foreach ($this->operationParameters($document) as $parameter) {
            $name = $parameter['name'] ?? null;
            if (\is_string($name) && \str_starts_with($name, 'filter[')) {
                $parameters[$name] = \array_intersect_key($parameter, ['schema' => true, 'style' => true, 'explode' => true, 'x-filter-list' => true]);
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(FilterInterface $filter): array
    {
        $server = new FakeServerMetadata(
            title: 'Leads',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'leads',
                    fields: [
                        Id::make()->build(),
                        BelongsTo::make('campaign', 'campaigns')->build(),
                        HasMany::make('templates', 'templates')->build(),
                        MorphTo::make('subject', ['campaigns', 'templates'])->identifiedByColumns('subjectType', 'subjectId')->build(),
                        DateTime::make('archivedAt')->nullable()->build(),
                    ],
                    filters: [$filter],
                ),
                FakeTypeMetadata::resource(type: 'campaigns', fields: [
                    Id::make()->numeric()->build(),
                    Str::make('name')->build(),
                    BelongsTo::make('advertiser', 'advertisers')->build(),
                ]),
                FakeTypeMetadata::resource(type: 'advertisers', fields: [Id::make()->numeric()->build()]),
                FakeTypeMetadata::resource(type: 'templates', fields: [Id::make()->uuid()->build()]),
            ],
        );

        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function parameter(array $document, string $name): array
    {
        foreach ($this->operationParameters($document) as $parameter) {
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail("No {$name} parameter on GET /leads.");
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function operationParameters(array $document): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths['/leads'] ?? null;
        self::assertIsArray($item);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation);
        $parameters = $operation['parameters'] ?? null;
        self::assertIsArray($parameters);

        $found = [];
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            /** @var array<string, mixed> $parameter */
            $found[] = $parameter;
        }

        return $found;
    }
}
