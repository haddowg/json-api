<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class SortFamilyProjectionTest extends TestCase
{
    #[Test]
    public function aTypeWithoutFamiliesKeepsTheFixedEnumAndTheBaselineContract(): void
    {
        $document = $this->document([SortByField::make('title')]);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['title', '-title']]],
            $this->sortSchema($document, '/campaigns'),
        );
        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->arrAt($document, 'info', 'x-generator'));
    }

    #[Test]
    public function aFamilyAddsAPatternBesideTheFixedEnumAndIsListedOnTheSchema(): void
    {
        $document = $this->document([
            SortByField::make('title'),
            $this->family('value')->withSuffixPattern('[a-z_]+'),
            $this->family('custom.fields'),
        ]);

        self::assertSame(
            [
                'type' => 'array',
                'items' => ['anyOf' => [
                    ['type' => 'string', 'enum' => ['title', '-title']],
                    ['type' => 'string', 'pattern' => '^-?value\.(?:[a-z_]+)$'],
                    ['type' => 'string', 'pattern' => '^-?custom\.fields\.(?:[^,]+)$'],
                ]],
                'x-sort-families' => [
                    ['prefix' => 'value', 'suffixPattern' => '[a-z_]+'],
                    ['prefix' => 'custom.fields', 'suffixPattern' => '[^,]+'],
                ],
            ],
            $this->sortSchema($document, '/campaigns'),
        );
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SORT_FAMILIES)], $this->arrAt($document, 'info', 'x-generator'));
    }

    #[Test]
    public function onlyFamiliesLeaveNoEmptyEnum(): void
    {
        self::assertSame(
            ['anyOf' => [['type' => 'string', 'pattern' => '^-?value\.(?:[^,]+)$']]],
            $this->arrAt($this->sortSchema($this->document([$this->family('value')]), '/campaigns'), 'items'),
        );
    }

    #[Test]
    public function theProjectedPatternAcceptsWhatTheMatcherAccepts(): void
    {
        $schema = \json_decode(\json_encode($this->sortSchema($this->document([
            SortByField::make('title'),
            $this->family('value')->withSuffixPattern('[a-z_]+'),
        ]), '/campaigns'), flags: \JSON_THROW_ON_ERROR));
        self::assertIsObject($schema);

        $validator = new Validator();
        foreach ([['title'], ['-value.end_date'], ['value.cap', '-title']] as $valid) {
            self::assertTrue($validator->validate($valid, $schema)->isValid(), \implode(',', $valid));
        }
        foreach ([['value'], ['value.Cap'], ['values.cap'], ['rating']] as $invalid) {
            self::assertFalse($validator->validate($invalid, $schema)->isValid(), \implode(',', $invalid));
        }
    }

    #[Test]
    public function aPrefixIsEscapedForTheEcmaPattern(): void
    {
        self::assertSame(
            ['anyOf' => [['type' => 'string', 'pattern' => '^-?a\+b\$#\.(?:[^,]+)$']]],
            $this->arrAt($this->sortSchema($this->document([$this->family('a+b$#')]), '/campaigns'), 'items'),
        );
    }

    #[Test]
    public function aRelationFamilySitsBesideTheRelatedTypesExactSortOfTheSameKey(): void
    {
        $document = $this->document(
            [SortByField::make('value')],
            [new FakeRelationMetadata('children', ['campaigns'], true, sorts: [$this->family('value')])],
        );

        $schema = $this->sortSchema($document, '/campaigns/{id}/children');
        self::assertSame(
            ['anyOf' => [
                ['type' => 'string', 'enum' => ['value', '-value']],
                ['type' => 'string', 'pattern' => '^-?value\.(?:[^,]+)$'],
            ]],
            $this->arrAt($schema, 'items'),
        );
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SORT_FAMILIES)], $this->arrAt($document, 'info', 'x-generator'));
    }

    private function family(string $prefix): SortFamily
    {
        return SortFamily::make($prefix, static fn(string $suffix): ?SortInterface => null);
    }

    /**
     * @param list<SortInterface>       $sorts
     * @param list<FakeRelationMetadata> $relations
     *
     * @return array<array-key, mixed>
     */
    private function document(array $sorts, array $relations = []): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'campaigns',
                fields: [Id::make()->build(), Str::make('title')->build()],
                relations: $relations,
                sorts: $sorts,
            )],
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function sortSchema(array $document, string $path): array
    {
        $parameters = $this->arrAt($document, 'paths', $path, 'get', 'parameters');
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            if (($parameter['name'] ?? null) === 'sort') {
                return $this->arrAt($parameter, 'schema');
            }
        }

        self::fail(\sprintf('%s has no sort parameter.', $path));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
