<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `filter[<key>]` parameters of the path-traversing filters: a {@see WhereThroughIn}
 * documents both list forms exactly as a {@see \haddowg\JsonApi\Resource\Filter\WhereIn}
 * declared with `acceptsList()` does, and a path that ends on a relationship takes its
 * value schema from the related id.
 */
#[CoversClass(OperationProjector::class)]
final class ThroughFilterProjectionTest extends TestCase
{
    #[Test]
    public function aListThroughARelationDocumentsBothFormsWithTheRelatedIdsType(): void
    {
        $parameters = $this->parameters(WhereThroughIn::make('advertiser', 'campaign.advertiser')->build());

        self::assertSame([
            'filter[advertiser]' => [
                'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'style' => 'form',
                'explode' => false,
            ],
            'filter[advertiser][]' => [
                'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'style' => 'form',
                'explode' => true,
                'x-filter-list' => ['key' => 'advertiser', 'delimited' => true],
            ],
        ], $parameters);
    }

    #[Test]
    public function aUuidRelatedIdDocumentsItsFormat(): void
    {
        $parameters = $this->parameters(WhereThroughIn::make('template', 'templates')->build());

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], $parameters['filter[template]']['schema']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], $parameters['filter[template][]']['schema']);
    }

    #[Test]
    public function anArrayOnlyListDocumentsOnlyTheArrayForm(): void
    {
        $parameters = $this->parameters(WhereThroughIn::make('advertiser', 'campaign.advertiser')->acceptsList(delimited: false)->build());

        self::assertSame(['filter[advertiser][]'], \array_keys($parameters));
        self::assertSame(['key' => 'advertiser', 'delimited' => false], $parameters['filter[advertiser][]']['x-filter-list']);
    }

    #[Test]
    public function aDeclaredConstraintWinsOverTheRelatedId(): void
    {
        $parameters = $this->parameters(WhereThroughIn::make('advertiser', 'campaign.advertiser')->pattern('^[a-z]+$')->build());

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z]+$']], $parameters['filter[advertiser][]']['schema']);
    }

    #[Test]
    public function aFieldLeafListStaysAStringList(): void
    {
        $parameters = $this->parameters(WhereThroughIn::make('campaign.name')->build());

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $parameters['filter[campaign.name]']['schema']);
    }

    #[Test]
    public function aWhereThroughOnARelationIsAScalarOfTheRelatedIdsType(): void
    {
        $parameters = $this->parameters(WhereThrough::make('advertiser', 'campaign.advertiser')->build());

        self::assertSame(['filter[advertiser]' => ['schema' => ['type' => 'integer']]], $parameters);
    }

    #[Test]
    public function theDescriptionExampleAndDeprecationCarryToBothParameters(): void
    {
        $document = $this->document(WhereThroughIn::make('advertiser', 'campaign.advertiser')
            ->describedAs('Leads of these advertisers.')
            ->example(['4', '7'])
            ->deprecated()
            ->build());

        $delimited = $this->parameter($document, 'filter[advertiser]');
        $array = $this->parameter($document, 'filter[advertiser][]');

        self::assertSame('Leads of these advertisers.', $delimited['description']);
        self::assertSame('Leads of these advertisers.', $array['description']);
        self::assertSame(['4', '7'], $array['example']);
        self::assertTrue($delimited['deprecated']);
        self::assertTrue($array['deprecated']);
    }

    #[Test]
    public function aPolymorphicLeafFailsTheProjection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('polymorphic relation "subject"');

        $this->document(WhereThroughIn::make('subject')->build());
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
                        MorphTo::make('subject', ['campaigns', 'templates'])->build(),
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
