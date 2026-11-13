<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationEnumerator;
use haddowg\JsonApi\OpenApi\OperationFacts;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Priority;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `relatedQuery` entry's `filter` members are the schemas of the related endpoint's
 * `filter[<key>]` parameters, so a filter declaring an enum references its component with
 * the same `type` there as it does on the related endpoint.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
final class RelatedQueryFilterSchemaTest extends TestCase
{
    #[Test]
    public function anEnumFilterReferencesItsComponentWithItsType(): void
    {
        $document = $this->project($this->server());
        $filter = self::node($document, 'components', 'parameters', 'AdvertisersRelatedQuery', 'schema', 'properties', 'logo', 'properties', 'filter', 'properties');

        self::assertSame(['type' => 'string', 'description' => 'Filter the collection by `status`.', 'allOf' => [['$ref' => '#/components/schemas/Status']]], $filter['status']);
        self::assertSame(
            ['type' => 'array', 'description' => 'Filter the collection by `priorities`.', 'items' => ['type' => 'integer', 'allOf' => [['$ref' => '#/components/schemas/Priority']]]],
            $filter['priorities'],
        );
        self::assertSame(
            ['type' => 'array', 'description' => 'Filter the collection by `ranks`.', 'items' => ['type' => 'integer', 'allOf' => [['$ref' => '#/components/schemas/Priority']]]],
            $filter['ranks'],
        );
    }

    #[Test]
    public function anEnumTheDocumentRenamesIsRenamedInsideTheComponentToo(): void
    {
        $document = $this->project($this->server());
        $filter = self::node($document, 'components', 'parameters', 'AdvertisersRelatedQuery', 'schema', 'properties', 'logo', 'properties', 'filter', 'properties');

        self::assertSame(['type' => 'string', 'description' => 'Filter the collection by `kind`.', 'allOf' => [['$ref' => '#/components/schemas/Links2']]], $filter['kind']);
        self::assertSame(['a', 'b'], self::node($document, 'components', 'schemas', 'Links2', 'enum'));
    }

    #[Test]
    public function eachFilterMatchesTheRelatedEndpointsParameter(): void
    {
        $server = $this->server();

        self::assertGreaterThan(0, $this->assertFiltersMatchRelatedEndpoints($server, $this->project($server)));
    }

    /**
     * Over the whole witness, every `relatedQuery` filter member is its related
     * endpoint's `filter[<key>]` schema, so the two cannot drift apart.
     */
    #[Test]
    public function eachFilterMatchesTheRelatedEndpointsParameterAcrossTheWitness(): void
    {
        $server = ContractWitnessServer::build();

        self::assertGreaterThan(10, $this->assertFiltersMatchRelatedEndpoints($server, $this->project($server)));
    }

    /**
     * Compares every `relatedQuery` entry's `filter` with the `filter` parameters of its
     * relation's related endpoint, and returns how many members it compared.
     *
     * @param array<string, mixed> $document
     */
    private function assertFiltersMatchRelatedEndpoints(ServerMetadataInterface $server, array $document): int
    {
        $compared = 0;
        $components = self::node($document, 'components', 'parameters');
        foreach ($server->types() as $type) {
            $name = OperationProjector::relatedQueryComponentName($type);
            if (!isset($components[$name])) {
                continue;
            }
            $entries = self::node($document, 'components', 'parameters', $name, 'schema', 'properties');
            foreach (OperationFacts::relatedQueryRelations($type) as $relation) {
                $path = null;
                foreach (OperationEnumerator::forType($type) as $operation) {
                    if ($operation->key->kind === OperationKind::FetchRelated && $operation->relation?->name() === $relation->name()) {
                        $path = $operation->path;
                    }
                }
                if ($path === null) {
                    continue;
                }
                $context = $name . '.' . $relation->name();

                [$expected, $patterns] = $this->relatedEndpointFilters($document, $path);
                $entry = self::node($entries, $relation->name());
                $properties = $entry['properties'] ?? [];
                self::assertIsArray($properties, $context);
                $filter = $properties['filter'] ?? null;
                if ($expected === [] && $patterns === null) {
                    self::assertNull($filter, $context);

                    continue;
                }
                self::assertIsArray($filter, $context);
                self::assertSame($patterns, $filter['patternProperties'] ?? null, $context . ' families');

                $members = $filter['properties'] ?? [];
                self::assertIsArray($members, $context);
                self::assertSame(\array_keys($expected), \array_keys($members), $context);
                foreach ($expected as $key => $forms) {
                    $member = $members[$key];
                    self::assertIsArray($member, $context . '.' . $key);
                    unset($member['description'], $member['deprecated']);
                    // A key taking both forms is the array when its delimited form is one
                    // too, and either form otherwise.
                    $accepted = match (true) {
                        \count($forms) === 1, ($forms[0]['type'] ?? null) === 'array' => $forms[0],
                        default => ['anyOf' => $forms],
                    };
                    self::assertSame($accepted, $member, $context . '.' . $key);
                    ++$compared;
                }
            }
        }

        return $compared;
    }

    /**
     * The schemas of a related endpoint's `filter[<key>]` and `filter[<key>][]`
     * parameters by key, in order and without their descriptions, and its filter
     * families' `patternProperties`.
     *
     * @param array<string, mixed> $document
     *
     * @return array{array<string, list<array<array-key, mixed>>>, mixed}
     */
    private function relatedEndpointFilters(array $document, string $path): array
    {
        $byKey = [];
        $patterns = null;
        foreach (self::node($document, 'paths', $path, 'get', 'parameters') as $parameter) {
            $name = \is_array($parameter) ? ($parameter['name'] ?? null) : null;
            if (!\is_string($name) || !\str_starts_with($name, 'filter')) {
                continue;
            }
            $schema = $parameter['schema'] ?? null;
            self::assertIsArray($schema, $path . ' ' . $name);
            if ($name === 'filter') {
                $patterns = $schema['patternProperties'] ?? null;

                continue;
            }
            $list = \str_ends_with($name, '[]');
            $key = \substr($name, \strlen('filter['), $list ? -3 : -1);
            unset($schema['description']);
            $byKey[$key][] = $schema;
        }

        return [$byKey, $patterns];
    }

    /**
     * `advertisers` with a to-one `logo` to `files`, whose filters declare a string
     * enum, an int enum taken as a delimited list and as an array, and an enum whose
     * name a shared component takes.
     */
    private function server(): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'advertisers',
                    fields: [Id::make()->build(), Str::make('name')->build()],
                    relations: [new FakeRelationMetadata('logo', ['files'], false)],
                ),
                FakeTypeMetadata::resource(
                    type: 'files',
                    fields: [Id::make()->build(), Str::make('status')->enum(Status::class)->build()],
                    filters: [
                        Where::make('status')->enum(Status::class)->build(),
                        WhereIn::make('priorities', 'priority')->enum(Priority::class)->build(),
                        WhereIn::make('ranks', 'priority')->enum(Priority::class)->acceptsList(delimited: false)->build(),
                        Where::make('kind')->enum(Links::class)->build(),
                    ],
                ),
            ],
            profiles: [RelationshipQueriesProfile::URI],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function project(ServerMetadataInterface $server): array
    {
        $decoded = \json_decode(\json_encode((new OpenApiProjector())->project($server)->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
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
}

enum Links: string
{
    case A = 'a';
    case B = 'b';
}
