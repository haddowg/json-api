<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\StandaloneResourceObject;
use haddowg\JsonApi\OpenApi\TypedRequestMeta;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\TypedRequestMetaTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandaloneResourceObject::class)]
#[CoversClass(RelationFacts::class)]
final class StandaloneResourceObjectTest extends TestCase
{
    #[Test]
    public function aTypesResourceMetaIsTypedInline(): void
    {
        $meta = self::member(self::project('bookings'), 'properties', 'meta');

        self::assertSame('object', $meta['type'] ?? null);
        self::assertSame(['nights', 'heldUntil'], \array_keys(self::member($meta, 'properties')));
        self::assertSame(['type' => ['string', 'null']], $meta['additionalProperties'] ?? null);
    }

    #[Test]
    public function aPivotTargetsResourceMetaAlsoTakesItsLinksPivot(): void
    {
        $properties = self::member(self::project('rooms'), 'properties', 'meta', 'properties');

        self::assertSame(['floor', 'pivot'], \array_keys($properties));
    }

    #[Test]
    public function aRelationsIdentifierMetaAndPivotAreTypedOnItsLinkage(): void
    {
        $relationships = self::member(self::project('bookings'), 'properties', 'relationships');
        self::assertSame(['rooms'], \array_keys(self::member($relationships, 'properties')));

        $linkage = self::member($relationships, 'properties', 'rooms', 'properties', 'data');
        self::assertSame('array', $linkage['type'] ?? null);
        $meta = self::member($linkage, 'items', 'properties', 'meta');
        self::assertSame(['view', 'pivot'], \array_keys(self::member($meta, 'properties')));
        self::assertTrue($meta['additionalProperties'] ?? null);
        $pivot = self::member($meta, 'properties', 'pivot');
        self::assertSame(['nights'], \array_keys(self::member($pivot, 'properties')));
        self::assertSame(['type' => ['string', 'null']], $pivot['additionalProperties'] ?? null);
    }

    #[Test]
    public function theTypedObjectsMatchTheOpenApiDocumentsComponents(): void
    {
        $components = self::member((new OpenApiProjector())->project(self::server())->toArray(), 'components', 'schemas');
        $bookings = self::project('bookings');

        $resourceMeta = self::member($components, 'BookingsResourceMeta');
        unset($resourceMeta['x-' . TypedRequestMeta::RESOURCE_EXTENSION]);
        self::assertSame($resourceMeta, self::member($bookings, 'properties', 'meta'));

        $identifierMeta = self::member($components, 'BookingsRoomsIdentifierMeta');
        self::assertSame(['$ref' => '#/components/schemas/BookingsRoomsPivot'], self::member($identifierMeta, 'properties', 'pivot'));
        $identifierMeta['properties'] = [...self::member($identifierMeta, 'properties'), 'pivot' => self::member($components, 'BookingsRoomsPivot')];
        self::assertSame($identifierMeta, self::member($bookings, 'properties', 'relationships', 'properties', 'rooms', 'properties', 'data', 'items', 'properties', 'meta'));
    }

    #[Test]
    public function aTypeDeclaringNoMetaKeepsTheOpenObjects(): void
    {
        $document = self::project('jobs');

        self::assertSame(['type' => 'object'], self::member($document, 'properties', 'meta'));
        self::assertSame(['type' => 'object'], self::member($document, 'properties', 'relationships'));
    }

    #[Test]
    public function aTypeTheServerDoesNotDescribeGetsThePlainProjection(): void
    {
        $fields = [Id::make()->build(), Str::make('name')->build()];
        $schemas = new SchemaProjector();

        self::assertEquals(
            $schemas->projectResourceObject('people', $fields)->toArray(),
            StandaloneResourceObject::project(self::server(), 'people', $fields, $schemas)->toArray(),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function project(string $type): array
    {
        return StandaloneResourceObject::project(self::server(), $type, [Id::make()->build()], new SchemaProjector())->toArray();
    }

    private static function server(): FakeServerMetadata
    {
        return new FakeServerMetadata('API', '1', [TypedRequestMetaTypes::bookings(), TypedRequestMetaTypes::rooms(declaresResourceMeta: true), TypedRequestMetaTypes::jobs()]);
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    private static function member(array $schema, string ...$path): array
    {
        foreach ($path as $key) {
            $next = $schema[$key] ?? null;
            self::assertIsArray($next, $key);
            $schema = $next;
        }

        return $schema;
    }
}
