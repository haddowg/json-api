<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Priority;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A filter declared with `enum()` references the enum's hoisted component, the one a
 * field declaring the same enum references, so both share one generated type.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(SchemaProjector::class)]
#[CoversClass(EnumComponentCollector::class)]
final class FilterEnumProjectionTest extends TestCase
{
    private const string STATUS = '#/components/schemas/Status';

    #[Test]
    public function aScalarFilterReferencesTheComponentItsFieldReferences(): void
    {
        $document = $this->document([Where::make('status')->enum(Status::class)->build()]);

        self::assertSame(['$ref' => self::STATUS], $this->schemaOf($document, 'filter[status]'));
        self::assertStringContainsString('"$ref":"' . \str_replace('/', '\\/', self::STATUS) . '"', \json_encode(self::node($document, 'components', 'schemas', 'WidgetsAttributes', 'properties', 'status'), \JSON_THROW_ON_ERROR));

        $component = self::node($document, 'components', 'schemas', 'Status');
        self::assertSame(['draft', 'published', 'archived'], $component['enum']);
        self::assertSame(['Draft', 'Published', 'Archived'], $component['x-enum-varnames']);
        self::assertSame(['Not yet visible to readers', 'Live and public', ''], $component['x-enum-descriptions']);
    }

    #[Test]
    public function aListFiltersMembersReferenceTheComponent(): void
    {
        $document = $this->document([
            WhereIn::make('status')->enum(Status::class)->acceptsList()->build(),
            WhereNotIn::make('notStatus', 'status')->enum(Status::class)->build(),
        ]);

        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::STATUS]], $this->schemaOf($document, 'filter[status]'));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::STATUS]], $this->schemaOf($document, 'filter[status][]'));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::STATUS]], $this->schemaOf($document, 'filter[notStatus]'));
    }

    #[Test]
    public function anEnumReferenceTakesNoSiblingStringType(): void
    {
        $document = $this->document([
            Where::make('status')->pattern('^[a-z]+$')->enum(Status::class)->build(),
            WhereIn::make('statuses', 'status')->enum(Status::class)->pattern('^[a-z]+$')->acceptsList()->build(),
        ]);

        self::assertSame(['$ref' => self::STATUS], $this->schemaOf($document, 'filter[status]'));
        // A pattern declared after the enum stays beside the reference, still with no type.
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::STATUS, 'pattern' => '^[a-z]+$']], $this->schemaOf($document, 'filter[statuses][]'));
    }

    #[Test]
    public function aThroughFilterReferencesTheComponent(): void
    {
        $document = $this->document([
            WhereThrough::make('ownerStatus', 'owner.status')->enum(Status::class)->build(),
            WhereThroughIn::make('ownerStatuses', 'owner.status')->enum(Status::class)->acceptsList(delimited: false)->build(),
        ]);

        self::assertSame(['$ref' => self::STATUS], $this->schemaOf($document, 'filter[ownerStatus]'));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::STATUS]], $this->schemaOf($document, 'filter[ownerStatuses][]'));
    }

    #[Test]
    public function anEnumOnlyAFilterDeclaresIsEmittedOnce(): void
    {
        $document = $this->document([
            Where::make('priority')->enum(Priority::class)->build(),
            WhereIn::make('priorities', 'priority')->enum(Priority::class)->build(),
        ]);

        self::assertSame(['$ref' => '#/components/schemas/Priority'], $this->schemaOf($document, 'filter[priority]'));
        self::assertSame(['enum' => [1, 2], 'x-enum-varnames' => ['Low', 'High']], self::node($document, 'components', 'schemas', 'Priority'));
    }

    #[Test]
    public function anEnumWhoseNameTheDocumentTakesIsRenamedOnTheFilterToo(): void
    {
        $document = $this->document([Where::make('kind')->enum(Meta::class)->build()]);

        self::assertSame(['$ref' => '#/components/schemas/Meta2'], $this->schemaOf($document, 'filter[kind]'));
        self::assertSame(['a', 'b'], self::node($document, 'components', 'schemas', 'Meta2', 'enum'));
        self::assertArrayNotHasKey('enum', self::node($document, 'components', 'schemas', 'Meta'));
    }

    #[Test]
    public function anOperationProjectedWithoutACollectorKeepsTheEnumInline(): void
    {
        $server = $this->server([Where::make('priority')->enum(Priority::class)->build()]);
        $type = $server->types()[0];

        $paths = (new OperationProjector())->projectType($type, $server);
        $parameters = $paths['/widgets']->operations['get']->parameters;
        $schema = null;
        foreach ($parameters as $parameter) {
            if ($parameter instanceof \haddowg\JsonApi\OpenApi\Parameter && $parameter->name === 'filter[priority]') {
                $schema = $parameter->schema?->toArray();
            }
        }

        self::assertSame(['enum' => [1, 2], 'x-enum-varnames' => ['Low', 'High']], $schema);
    }

    /**
     * The array at `$keys` below `$node`.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function node(array $node, string ...$keys): array
    {
        foreach ($keys as $key) {
            $node = $node[$key] ?? null;
            self::assertIsArray($node, $key);
        }

        return $node;
    }

    /**
     * @param list<FilterInterface> $filters
     */
    private function server(array $filters): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'Widgets',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'widgets', fields: [
                    Id::make()->build(),
                    Str::make('status')->enum(Status::class)->build(),
                    BelongsTo::make('owner', 'people')->build(),
                ], filters: $filters),
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('status')->enum(Status::class)->build()]),
            ],
        );
    }

    /**
     * @param list<FilterInterface> $filters
     *
     * @return array<string, mixed>
     */
    private function document(array $filters): array
    {
        $document = (new OpenApiProjector())->project($this->server($filters))->toArray();
        // Round-trip through JSON so nested components read as plain arrays.
        $decoded = \json_decode(\json_encode($document, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function schemaOf(array $document, string $name): array
    {
        foreach (self::node($document, 'paths', '/widgets', 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                $schema = $parameter['schema'] ?? null;
                self::assertIsArray($schema);

                return $schema;
            }
        }

        self::fail("No {$name} parameter on GET /widgets.");
    }
}

enum Meta: string
{
    case A = 'a';
    case B = 'b';
}
