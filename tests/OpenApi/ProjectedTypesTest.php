<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\ProjectedTypes;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the invariant {@see ProjectedTypes} exists to hold: the type set it reports is
 * **exactly** the set the projected document gives a `<Type>Resource` component to.
 *
 * A framework integration emits a second artifact — the per-type JSON Schema bundle —
 * from the same metadata, and derives its keys from this class. If the two ever drift,
 * one server ships two artifacts that disagree about which types it describes, which is
 * the failure these assertions exist to catch.
 */
#[CoversClass(ProjectedTypes::class)]
final class ProjectedTypesTest extends TestCase
{
    /**
     * The document's `<Type>Resource` components, as the JSON:API types they pin via
     * their `type` const — read back out of the projected document rather than
     * recomputed, so the assertion compares two independent derivations.
     *
     * @return list<string>
     */
    private function resourceObjectTypes(ServerMetadataInterface $server): array
    {
        $document = (new OpenApiProjector())->project($server)->toArray();
        $schemas = $this->arrAt($document, 'components', 'schemas');

        $types = [];
        foreach (\array_keys($schemas) as $name) {
            // Exact suffix, so `<Type>ResourceIdentifier` and `<Type>CreateRequest` stay
            // out — only the resource object is the contract this class enumerates.
            if (!\is_string($name) || !\str_ends_with($name, 'Resource')) {
                continue;
            }
            $types[] = $this->strAt($schemas, $name, 'properties', 'type', 'const');
        }

        \sort($types);

        return $types;
    }

    /**
     * Walks a nested array by key path, narrowing at each step (the idiom the sibling
     * projector tests use).
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function arrAt(array $schema, string ...$keys): array
    {
        $cursor = $schema;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }
        self::assertIsArray($cursor);

        return $cursor;
    }

    /**
     * Like {@see arrAt()} but asserts (and types) the leaf as a string.
     *
     * @param array<array-key, mixed> $schema
     */
    private function strAt(array $schema, string ...$keys): string
    {
        $cursor = $schema;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }
        self::assertIsString($cursor);

        return $cursor;
    }

    /**
     * A server whose relation crosses its own boundary: `favorites` is registered and
     * points at `users`, which is registered on a *different* server. The related
     * endpoint is exposed, so `GET /favorites/{id}/user` really does return a `users`
     * resource object and the document synthesizes one for it.
     */
    private function crossServerBoundary(): FakeServerMetadata
    {
        $favorites = FakeTypeMetadata::resource(
            type: 'favorites',
            fields: [Id::make()->build(), Str::make('label')->required()->build()],
            relations: [FakeRelationMetadata::toOne('user', ['users'])],
        );

        return new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$favorites]);
    }

    #[Test]
    public function itReportsARelatedOnlyTypeTheServerDoesNotRegister(): void
    {
        $server = $this->crossServerBoundary();

        self::assertSame(['favorites'], ProjectedTypes::registered($server));
        self::assertSame(['users'], ProjectedTypes::relatedOnly($server));
        self::assertSame(['favorites', 'users'], ProjectedTypes::forServer($server));
    }

    /**
     * The whole point: the document describes `users`, so the accessor must too.
     * Deriving a second artifact from `ServerMetadataInterface::types()` alone is the
     * bug this replaces — it would report `favorites` only.
     */
    #[Test]
    public function theReportedSetMatchesTheDocumentsResourceObjects(): void
    {
        $server = $this->crossServerBoundary();

        $reported = ProjectedTypes::forServer($server);
        \sort($reported);

        self::assertSame($this->resourceObjectTypes($server), $reported);
        self::assertContains('users', $reported);
    }

    /**
     * A linkage-only related type gets a `ResourceIdentifier` and no resource object,
     * so it is deliberately **not** reported — there is no resource-object contract to
     * describe, and a bundle entry for it would validate nothing.
     */
    #[Test]
    public function itOmitsARelatedTypeReachedOnlyAsLinkage(): void
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->required()->build()],
            relations: [new FakeRelationMetadata('category', ['categories'], false, relatedEndpoint: false)],
        );

        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$articles]);

        self::assertSame([], ProjectedTypes::relatedOnly($server));
        self::assertSame(['articles'], ProjectedTypes::forServer($server));
        self::assertSame(['articles'], $this->resourceObjectTypes($server));
    }

    /**
     * A standalone-serializer type carries no field inventory but is registered, so it
     * is reported (and the document gives it a fieldless resource object) — "no fields"
     * was never the exclusion rule.
     */
    #[Test]
    public function itReportsAFieldlessRegisteredType(): void
    {
        $charts = FakeTypeMetadata::standalone('charts');
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$charts]);

        self::assertSame(['charts'], ProjectedTypes::forServer($server));
        self::assertSame(['charts'], $this->resourceObjectTypes($server));
    }

    /**
     * A polymorphic relation contributes every unregistered member, deduped, and a
     * member that *is* registered is never double-counted.
     */
    #[Test]
    public function itDedupesAcrossRelationsAndSkipsRegisteredMembers(): void
    {
        $comments = FakeTypeMetadata::resource(
            type: 'comments',
            fields: [Id::make()->build()],
            relations: [
                FakeRelationMetadata::toOne('author', ['people', 'bots']),
                FakeRelationMetadata::toMany('mentions', ['people', 'comments']),
            ],
        );

        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$comments]);

        self::assertSame(['people', 'bots'], ProjectedTypes::relatedOnly($server));
        self::assertSame(['comments', 'people', 'bots'], ProjectedTypes::forServer($server));

        $reported = ProjectedTypes::forServer($server);
        \sort($reported);
        self::assertSame($this->resourceObjectTypes($server), $reported);
    }

    /**
     * The registered list preserves registration order — the bundle keys its entries in
     * the same order the document projects them.
     */
    #[Test]
    public function itPreservesRegistrationOrder(): void
    {
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(type: 'zebras', fields: [Id::make()->build()]),
            FakeTypeMetadata::resource(type: 'apples', fields: [Id::make()->build()]),
        ]);

        self::assertSame(['zebras', 'apples'], ProjectedTypes::registered($server));
    }
}
