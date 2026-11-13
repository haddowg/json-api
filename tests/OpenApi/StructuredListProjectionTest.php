<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\MissingExample;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A structured list's `items` is its element's schema, projected as that `Obj` or
 * `OneOf` would project as an attribute.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(GeneratorContract::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
final class StructuredListProjectionTest extends TestCase
{
    #[Test]
    public function anObjListProjectsItsElementPerRepresentation(): void
    {
        $field = $this->sources();

        $element = [
            'type' => 'object',
            'properties' => [
                'href' => ['type' => 'string', 'format' => 'uri', 'minLength' => 1],
                'retrievedAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['href'],
            'additionalProperties' => false,
        ];
        self::assertSame(['type' => 'array', 'description' => 'Where the article came from.', 'maxItems' => 5, 'items' => $element], $this->attribute($field, RepresentationContext::Create));
        // An update builds every element whole, so it requires what a create does.
        self::assertSame(['type' => 'array', 'description' => 'Where the article came from.', 'maxItems' => 5, 'items' => $element], $this->attribute($field, RepresentationContext::Update));
        self::assertSame([
            'type' => ['array', 'null'],
            'description' => 'Where the article came from.',
            'maxItems' => 5,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'href' => ['type' => 'string', 'format' => 'uri', 'minLength' => 1],
                    'retrievedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
                'required' => ['href', 'retrievedAt'],
                'additionalProperties' => false,
            ],
        ], $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function aOneOfListProjectsADiscriminatedUnionOfItems(): void
    {
        $field = $this->steps();

        $trim = [
            'type' => 'object',
            'properties' => ['operation' => ['type' => 'string', 'const' => 'trim']],
            'required' => ['operation'],
            'additionalProperties' => false,
        ];
        $findReplace = static fn(array $params, array $required): array => [
            'type' => 'object',
            'properties' => [
                'params' => $params,
                'operation' => ['type' => 'string', 'const' => 'find_replace'],
            ],
            'required' => $required,
            'additionalProperties' => false,
        ];
        $writeParams = [
            'type' => 'object',
            'properties' => ['find' => ['type' => 'string'], 'replace' => ['type' => 'string']],
            'additionalProperties' => false,
        ];

        foreach ([RepresentationContext::Create, RepresentationContext::Update] as $context) {
            self::assertSame([
                'type' => 'array',
                'maxItems' => 50,
                'items' => [
                    'description' => 'One edit applied in order.',
                    'oneOf' => [$trim, $findReplace($writeParams, ['operation'])],
                    'examples' => [['operation' => 'trim']],
                    'discriminator' => ['propertyName' => 'operation'],
                ],
            ], $this->attribute($field, $context), $context->name);
        }

        self::assertSame([
            'type' => ['array', 'null'],
            'maxItems' => 50,
            'items' => [
                'description' => 'One edit applied in order.',
                'oneOf' => [
                    $trim,
                    $findReplace(['type' => ['object', 'null'], 'properties' => $writeParams['properties'], 'required' => ['find', 'replace'], 'additionalProperties' => false], ['params', 'operation']),
                ],
                'examples' => [['operation' => 'trim']],
                'discriminator' => ['propertyName' => 'operation'],
            ],
        ], $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function aSerializeHookedListRequiresNoElementMembersOnRead(): void
    {
        $field = ArrayList::make('steps')
            ->of(OneOf::make('step')->discriminator('operation')->variant('trim', Str::make('mode')))
            ->serializeUsing(static fn(mixed $model): array => [])
            ->build();

        $items = $this->attribute($field, RepresentationContext::Read)['items'] ?? null;
        self::assertIsArray($items);
        self::assertIsArray($items['oneOf'] ?? null);
        self::assertSame(['type' => 'object', 'properties' => [
            'mode' => ['type' => ['string', 'null']],
            'operation' => ['type' => 'string', 'const' => 'trim'],
        ]], $items['oneOf'][0]);
    }

    #[Test]
    public function anEnumChildInsideAnElementIsHoistedIntoAComponent(): void
    {
        $collector = new EnumComponentCollector();
        $field = ArrayList::make('history')->of(Obj::make('entry')->fields(Str::make('status')->enum(Status::class)))->build();

        $schema = (new SchemaProjector())->projectField($field, true, $collector)->toArray();

        self::assertSame(['type' => 'string', 'allOf' => [['$ref' => '#/components/schemas/Status']]], self::at($schema, 'items', 'properties', 'status'));
        self::assertArrayHasKey('Status', $collector->components());
    }

    #[Test]
    public function theProjectedItemsAcceptWhatTheListWritesAndRefuseWhatItDoesNot(): void
    {
        $items = \json_encode($this->attribute($this->steps(), RepresentationContext::Create)['items'] ?? null, \JSON_THROW_ON_ERROR);
        $validator = new Validator();
        $valid = static fn(mixed $element): bool => $validator->validate(\json_decode(\json_encode($element, \JSON_THROW_ON_ERROR)), $items)->isValid();

        self::assertTrue($valid(['operation' => 'trim']));
        self::assertTrue($valid(['operation' => 'find_replace', 'params' => ['find' => 'a', 'replace' => 'b']]));
        self::assertFalse($valid(['operation' => 'shout']));
        self::assertFalse($valid(['params' => ['find' => 'a']]));
        self::assertFalse($valid(['operation' => 'find_replace', 'params' => ['find' => 1]]));
    }

    #[Test]
    public function aDocumentWithAStructuredListCarriesItsScopedContract(): void
    {
        $plain = $this->project([Id::make()->build(), ArrayList::make('tags')->build()]);
        $listed = $this->project([Id::make()->build(), $this->steps()]);
        $nested = $this->project([Id::make()->build(), Obj::make('meta')->fields($this->sources())->build()]);

        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($plain, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::STRUCTURED_LIST_ELEMENTS)], self::at($listed, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::STRUCTURED_LIST_ELEMENTS)], self::at($nested, 'info', 'x-generator'));
    }

    #[Test]
    public function anOperationExampleIsBuiltFromTheElementsChildren(): void
    {
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([
            Id::make()->example('a-1')->build(),
            ArrayList::make('sources')->of(Obj::make('source')->fields(
                Str::make('href')->example('https://a.example'),
                DateTime::make('retrievedAt')->example('2024-07-01T12:30:00+00:00'),
            ))->build(),
        ]));
        $document = $projection->document->toArray();

        self::assertSame(
            [['href' => 'https://a.example', 'retrievedAt' => '2024-07-01T12:30:00+00:00']],
            self::at($document, 'paths', '/articles/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'attributes', 'sources'),
        );
        self::assertSame([], $projection->missingExamples);
    }

    #[Test]
    public function anElementChildWithoutAnExampleIsMissingLikeAnObjChild(): void
    {
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([
            Id::make()->example('a-1')->build(),
            ArrayList::make('sources')->of(Obj::make('source')->fields(
                Str::make('href')->example('https://a.example'),
                DateTime::make('retrievedAt'),
            ))->build(),
        ]));
        $missing = \array_values(\array_unique(\array_map(static fn(MissingExample $m): string => $m->field, $projection->missingExamples)));

        self::assertSame(['sources.retrievedAt'], $missing);
    }

    private function sources(): ArrayList
    {
        return ArrayList::make('sources')
            ->of(Obj::make('source')->fields(
                Url::make('href')->required(),
                DateTime::make('retrievedAt'),
            ))
            ->maxItems(5)
            ->describedAs('Where the article came from.')
            ->build();
    }

    private function steps(): ArrayList
    {
        return ArrayList::make('steps')
            ->of(OneOf::make('step')
                ->discriminator('operation')
                ->variant('trim')
                ->variant('find_replace', Obj::make('params')->fields(
                    Str::make('find')->guaranteed(),
                    Str::make('replace')->guaranteed(),
                ))
                ->describedAs('One edit applied in order.')
                ->example(['operation' => 'trim']))
            ->maxItems(50)
            ->build();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function attribute(FieldInterface $field, RepresentationContext $context): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context)->toArray();
        $schema = self::at($attributes, 'properties', $field->name());
        self::assertIsArray($schema);

        return $schema;
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return array<array-key, mixed>
     */
    private function project(array $fields): array
    {
        return (new OpenApiProjector())->project($this->server($fields))->toArray();
    }

    /**
     * @param list<FieldInterface> $fields
     */
    private function server(array $fields): FakeServerMetadata
    {
        return new FakeServerMetadata('API', '1.0.0', [FakeTypeMetadata::resource('articles', $fields)], servers: [new Server('https://api.example.com')]);
    }

    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
