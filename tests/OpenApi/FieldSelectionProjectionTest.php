<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `fields[<type>]` parameter documents the `<attribute>.<key>` members an attribute
 * declaring selectable keys accepts, beside the member enum, and the document carries the
 * field-selection contract only then.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class FieldSelectionProjectionTest extends TestCase
{
    #[Test]
    public function theFieldsParameterAcceptsTheMemberEnumOrAKeyOfEachSelectableAttribute(): void
    {
        $parameter = $this->fieldsParameter($this->document(), '/products', 'fields[products]');

        self::assertSame([
            'type' => 'array',
            'items' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['name', 'values', 'totals', 'closed', 'vendor']],
                    ['type' => 'string', 'pattern' => '^values\\.(?:[a-z][a-z0-9_]*)$'],
                    ['type' => 'string', 'pattern' => '^totals\\.[^.,*]+$'],
                ],
            ],
        ], $parameter['schema']);
        self::assertIsString($parameter['description']);
        self::assertStringContainsString(FieldSelectionProfile::URI, $parameter['description']);
        self::assertStringContainsString('`values` or `totals`', $parameter['description']);
    }

    #[Test]
    public function anIncludedTypesFieldsParameterAcceptsItsKeysToo(): void
    {
        $parameter = $this->fieldsParameter($this->document(), '/products', 'fields[vendors]');

        self::assertSame(
            ['type' => 'string', 'pattern' => '^stats\\.[^.,*]+$'],
            $this->arr($this->arr($this->arr($parameter['schema'])['items'])['anyOf'])[1],
        );
    }

    #[Test]
    public function eachNestedObjectDeclaringSelectableKeysAddsAPatternForItsPath(): void
    {
        $parameter = $this->fieldsParameter($this->nestedDocument(), '/people', 'fields[people]');

        self::assertSame([
            'type' => 'array',
            'items' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['name', 'address', 'scores']],
                    ['type' => 'string', 'pattern' => '^address\\.(?:[a-z]+)$'],
                    ['type' => 'string', 'pattern' => '^address\\.geo\\.(?:lat|lng)$'],
                    ['type' => 'string', 'pattern' => '^scores\\.[^.,*]+$'],
                    ['type' => 'string', 'pattern' => '^scores\\.[^.,*]+\\.(?:min|max)$'],
                ],
            ],
        ], $parameter['schema']);
        self::assertIsString($parameter['description']);
        self::assertStringContainsString('`address` or `scores`', $parameter['description']);
        self::assertStringContainsString('`<attribute>.<key>.<key>`', $parameter['description']);
    }

    #[Test]
    public function aNestedObjectTheParentCannotReachAddsNoPattern(): void
    {
        $parameter = $this->fieldsParameter($this->nestedDocument(), '/people', 'fields[people]');
        $patterns = \array_column(\array_slice($this->arr($this->arr($this->arr($parameter['schema'])['items'])['anyOf']), 1), 'pattern');

        // `Region` fails the parent's pattern, `hidden` never renders and `history` is a list.
        foreach ($patterns as $pattern) {
            self::assertIsString($pattern);
            self::assertStringNotContainsString('Region', $pattern);
            self::assertStringNotContainsString('hidden', $pattern);
            self::assertStringNotContainsString('history', $pattern);
        }
    }

    #[Test]
    public function aListOfSelectableObjectsAddsAWildcardPatternForEachPathIntoItsItems(): void
    {
        $parameter = $this->fieldsParameter($this->listDocument(), '/orders', 'fields[orders]');

        self::assertSame([
            'type' => 'array',
            'items' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['lines', 'notes', 'plainLines', 'shipping']],
                    ['type' => 'string', 'pattern' => '^lines\\.\\*\\.(?:sku|qty|price|tags)$'],
                    ['type' => 'string', 'pattern' => '^lines\\.\\*\\.price\\.(?:amount|currency)$'],
                    ['type' => 'string', 'pattern' => '^lines\\.\\*\\.tags\\.\\*\\.(?:name|colour)$'],
                    ['type' => 'string', 'pattern' => '^shipping\\.(?:[a-z]+)$'],
                    ['type' => 'string', 'pattern' => '^shipping\\.parcels\\.\\*\\.(?:weight|label)$'],
                ],
            ],
        ], $parameter['schema']);
        self::assertIsString($parameter['description']);
        self::assertStringContainsString('Select single keys of `shipping` as `<attribute>.<key>`', $parameter['description']);
        self::assertStringContainsString('Select keys of every item of `lines` as `<attribute>.*.<key>`; the list keeps its length and order.', $parameter['description']);
        self::assertStringContainsString('`<attribute>.<key>.<key>`', $parameter['description']);
        self::assertStringContainsString('`<attribute>.<key>.*.<key>`', $parameter['description']);
    }

    #[Test]
    public function aTypeWhoseOnlySelectableAttributeIsAListLinksTheProfileFromTheListSentence(): void
    {
        $document = $this->listDocument(objects: false);
        $parameter = $this->fieldsParameter($document, '/orders', 'fields[orders]');

        self::assertIsString($parameter['description']);
        self::assertStringContainsString(
            'Select keys of every item of `lines` as `<attribute>.*.<key>` under the [Field Selection profile](' . FieldSelectionProfile::URI . '); the list keeps its length and order, and listing the attribute itself selects all of it.',
            $parameter['description'],
        );
        self::assertStringNotContainsString('single keys', $parameter['description']);
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::FIELD_SELECTION)], $this->arr($document['info'])['x-generator']);
    }

    #[Test]
    public function aListWhoseElementDeclaresNoSelectableKeysAddsNoPattern(): void
    {
        $parameter = $this->fieldsParameter($this->listDocument(), '/orders', 'fields[orders]');

        foreach (\array_column(\array_slice($this->arr($this->arr($this->arr($parameter['schema'])['items'])['anyOf']), 1), 'pattern') as $pattern) {
            self::assertIsString($pattern);
            self::assertStringNotContainsString('notes', $pattern);
            self::assertStringNotContainsString('plainLines', $pattern);
        }
    }

    #[Test]
    public function aDocumentWithOnlyTopLevelKeysKeepsItsDescription(): void
    {
        $parameter = $this->fieldsParameter($this->document(), '/products', 'fields[products]');

        self::assertIsString($parameter['description']);
        self::assertStringNotContainsString('nested', $parameter['description']);
    }

    #[Test]
    public function aTypeWithoutSelectableKeysKeepsThePlainEnum(): void
    {
        $document = $this->document(selectable: false);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['name', 'values', 'totals', 'closed', 'vendor']]],
            $this->fieldsParameter($document, '/products', 'fields[products]')['schema'],
        );
    }

    #[Test]
    public function onlyADocumentAcceptingKeysCarriesTheFieldSelectionContract(): void
    {
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::FIELD_SELECTION)], $this->arr($this->document()['info'])['x-generator']);
        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->arr($this->document(selectable: false)['info'])['x-generator']);
    }

    #[Test]
    public function theAttributeSchemaIsUnchanged(): void
    {
        $with = $this->arr($this->arr($this->document()['components'])['schemas']);
        $without = $this->arr($this->arr($this->document(selectable: false)['components'])['schemas']);

        self::assertSame($without['ProductsAttributes'], $with['ProductsAttributes']);
    }

    #[Test]
    public function theProfileIsListedWithTheOthersWhenTheServerRegistersIt(): void
    {
        $schemas = $this->arr($this->arr($this->document()['components'])['schemas']);
        $jsonApi = \json_encode($schemas['JsonApi'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertStringContainsString(FieldSelectionProfile::URI, $jsonApi);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(bool $selectable = true): array
    {
        $values = Obj::make('values')->additionalProperties(Str::make('*'));
        $totals = Map::make('totals')->fields(Integer::make('views'));
        if ($selectable) {
            $values->selectableKeys(pattern: '[a-z][a-z0-9_]*');
            $totals->selectableKeys(static fn(string $key): bool => true);
        }
        $stats = Obj::make('stats')->additionalProperties(Integer::make('*'));
        if ($selectable) {
            $stats->selectableKeys(static fn(string $key): bool => true);
        }

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'products',
                    fields: [Id::make()->build(), Str::make('name')->build(), $values->build(), $totals->build(), Obj::make('closed')->fields(Str::make('a'))->build()],
                    relations: [FakeRelationMetadata::toOne('vendor', ['vendors'])],
                    includablePaths: ['vendor'],
                ),
                FakeTypeMetadata::resource(type: 'vendors', fields: [Id::make()->build(), $stats->build()]),
            ],
            profiles: [FieldSelectionProfile::URI],
        ))->toArray();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function nestedDocument(): array
    {
        $address = Obj::make('address')->fields(
            Str::make('postcode'),
            Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->selectableKeys(pattern: 'lat|lng'),
            Obj::make('Region')->fields(Str::make('code'))->selectableKeys(pattern: '[a-z]+'),
            Obj::make('hidden')->fields(Str::make('code'))->selectableKeys(pattern: '[a-z]+')->hidden(),
            ArrayList::make('history')->of(Obj::make('entry')->fields(Str::make('street'))),
        )->selectableKeys(pattern: '[a-z]+');
        $scores = Obj::make('scores')
            ->additionalProperties(Obj::make('*')->fields(Integer::make('min'), Integer::make('max'))->selectableKeys(pattern: 'min|max'))
            ->selectableKeys(static fn(string $key): bool => true);

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build(), $address->build(), $scores->build()]),
            ],
            profiles: [FieldSelectionProfile::URI],
        ))->toArray();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function listDocument(bool $objects = true): array
    {
        $lines = ArrayList::make('lines')->of(Obj::make('line')->fields(
            Str::make('sku'),
            Integer::make('qty'),
            Obj::make('price')->fields(Integer::make('amount'), Str::make('currency'))->selectableKeys(pattern: 'amount|currency'),
            ArrayList::make('tags')->of(Obj::make('tag')->fields(Str::make('name'), Str::make('colour'))->selectableKeys(pattern: 'name|colour')),
        )->selectableKeys(pattern: 'sku|qty|price|tags'));
        $shipping = Obj::make('shipping')->fields(
            Str::make('carrier'),
            ArrayList::make('parcels')->of(Obj::make('parcel')->fields(Integer::make('weight'), Str::make('label'))->selectableKeys(pattern: 'weight|label')),
        );
        if ($objects) {
            $shipping->selectableKeys(pattern: '[a-z]+');
        }

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'orders', fields: [
                    Id::make()->build(),
                    $lines->build(),
                    ArrayList::make('notes')->build(),
                    ArrayList::make('plainLines')->of(Obj::make('line')->fields(Str::make('sku')))->build(),
                    $shipping->build(),
                ]),
            ],
            profiles: [FieldSelectionProfile::URI],
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function fieldsParameter(array $document, string $path, string $name): array
    {
        foreach ($this->arr($this->arr($this->arr($this->arr($document['paths'])[$path])['get'])['parameters']) as $parameter) {
            $parameter = $this->arr($parameter);
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail('No ' . $name . ' parameter on ' . $path . '.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
