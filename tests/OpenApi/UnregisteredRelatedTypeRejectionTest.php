<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RelatedTypeNotRegistered;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The projector refuses a server whose relation exposes its **related** endpoint to a
 * type the server does not register, rather than inventing an open resource object for
 * it.
 *
 * The second half of the class is why. It runs that configuration — a `favorites` server
 * that never registers `users` — and records what the server actually does: the related
 * endpoint cannot resolve a serializer and raises a 500, and both the resource document's
 * `user` relationship and the relationship-linkage document come back with no `data`
 * member at all. Keep the two halves together; the runtime evidence is the argument for
 * the guard, and deleting it leaves the guard looking arbitrary.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(RelatedTypeNotRegistered::class)]
#[Group('spec:document-structure')]
final class UnregisteredRelatedTypeRejectionTest extends TestCase
{
    /**
     * `favorites` is registered and points at `users`, which lives on another server.
     *
     * @param list<string>           $relatedTypes
     * @param list<FakeTypeMetadata> $alsoRegistered
     */
    private function favoritesServer(array $relatedTypes = ['users'], bool $relatedEndpoint = true, array $alsoRegistered = []): FakeServerMetadata
    {
        $favorites = FakeTypeMetadata::resource(
            type: 'favorites',
            fields: [Id::make()->build(), Str::make('label')->required()->build()],
            relations: [new FakeRelationMetadata('user', $relatedTypes, false, relatedEndpoint: $relatedEndpoint)],
        );

        return new FakeServerMetadata(
            title: 'Music Catalog API',
            version: '1.0.0',
            types: \array_values([$favorites, ...$alsoRegistered]),
        );
    }

    // ---- The refusal ----------------------------------------------------------------

    #[Test]
    public function itRefusesToDescribeATypeTheServerDoesNotRegister(): void
    {
        try {
            (new OpenApiProjector())->project($this->favoritesServer());
            self::fail('Expected RelatedTypeNotRegistered.');
        } catch (RelatedTypeNotRegistered $e) {
            self::assertSame('Music Catalog API', $e->server);
            self::assertSame('favorites', $e->parentType);
            self::assertSame('user', $e->relation);
            self::assertSame('users', $e->relatedType);
        }
    }

    /**
     * The message has to be actionable on its own — an export failure is read without the
     * stack trace to hand — so it names all four coordinates and both honest fixes.
     */
    #[Test]
    public function theMessageNamesTheOffenderAndTheWaysOut(): void
    {
        $this->expectException(RelatedTypeNotRegistered::class);
        $this->expectExceptionMessageMatches('/Relation "user" on type "favorites"/');
        $this->expectExceptionMessageMatches('/type "users", which is not registered on server "Music Catalog API"/');
        $this->expectExceptionMessageMatches('/Register "users" on this server/');
        $this->expectExceptionMessageMatches('/withoutRelatedEndpoint\(\)/');

        (new OpenApiProjector())->project($this->favoritesServer());
    }

    /**
     * A polymorphic relation is refused for the member it cannot describe, even when its
     * siblings are registered — the document would otherwise offer one honest arm and one
     * invented one in the same `anyOf`.
     */
    #[Test]
    public function itRefusesThePolymorphicMemberItCannotDescribe(): void
    {
        $users = FakeTypeMetadata::resource(type: 'users', fields: [Id::make()->build(), Str::make('name')->build()]);

        try {
            (new OpenApiProjector())->project($this->favoritesServer(['users', 'bots'], alsoRegistered: [$users]));
            self::fail('Expected RelatedTypeNotRegistered.');
        } catch (RelatedTypeNotRegistered $e) {
            self::assertSame('bots', $e->relatedType);
        }
    }

    // ---- The three ways out ---------------------------------------------------------

    #[Test]
    public function registeringTheRelatedTypeResolvesIt(): void
    {
        $users = FakeTypeMetadata::resource(type: 'users', fields: [Id::make()->build(), Str::make('name')->required()->build()]);
        $document = (new OpenApiProjector())->project($this->favoritesServer(alsoRegistered: [$users]))->toArray();

        $schemas = $this->schemas($document);
        self::assertArrayHasKey('UsersResource', $schemas);

        // Projected from the type's own fields, not invented: `name` is really there.
        self::assertArrayHasKey('UsersAttributes', $schemas);
        self::assertArrayHasKey('/favorites/{id}/user', $this->arr($document, 'paths'));
    }

    /**
     * The demo's worked answer where the full type belongs to another server: point the
     * relation at a reduced type this server does register.
     */
    #[Test]
    public function pointingTheRelationAtAReducedRegisteredTypeResolvesIt(): void
    {
        $profiles = FakeTypeMetadata::resource(
            type: 'public-profiles',
            fields: [Id::make()->build(), Str::make('displayName')->required()->build()],
        );
        $document = (new OpenApiProjector())->project($this->favoritesServer(['public-profiles'], alsoRegistered: [$profiles]))->toArray();

        self::assertArrayHasKey('PublicProfilesResource', $this->schemas($document));
    }

    /**
     * Linkage-only stays legitimate and untouched: an identifier is `{type, id}` and
     * asserts no shape, so a server may point linkage at a type it knows nothing else
     * about. No resource object, no related path, no refusal.
     */
    #[Test]
    public function suppressingTheRelatedEndpointResolvesItAndKeepsTheIdentifierStub(): void
    {
        $document = (new OpenApiProjector())->project($this->favoritesServer(relatedEndpoint: false))->toArray();
        $schemas = $this->schemas($document);

        self::assertArrayHasKey('UsersResourceIdentifier', $schemas);
        self::assertSame('users', $this->str($schemas, 'UsersResourceIdentifier', 'properties', 'type', 'const'));
        self::assertArrayNotHasKey('UsersResource', $schemas);
        self::assertArrayNotHasKey('UsersCollection', $schemas);
        self::assertArrayNotHasKey('/favorites/{id}/user', $this->arr($document, 'paths'));
    }

    /**
     * Two servers describing the same JSON:API type differently is a supported versioning
     * pattern, not the fault this guard is about: each projects from its own
     * registrations, so each states a shape it can actually honour. The fault is
     * describing a type you do not serve.
     */
    #[Test]
    public function twoServersMayDescribeTheSameTypeWithDifferentShapes(): void
    {
        $v1 = new FakeServerMetadata(title: 'v1', version: '1.0.0', types: [
            FakeTypeMetadata::resource(type: 'users', fields: [Id::make()->build(), Str::make('name')->required()->build()]),
        ]);
        $v2 = new FakeServerMetadata(title: 'v2', version: '2.0.0', types: [
            FakeTypeMetadata::resource(type: 'users', fields: [
                Id::make()->build(),
                Str::make('givenName')->required()->build(),
                Str::make('familyName')->required()->build(),
            ]),
        ]);

        $projector = new OpenApiProjector();
        $first = $this->arr($projector->project($v1)->toArray(), 'components', 'schemas', 'UsersAttributes', 'properties');
        $second = $this->arr($projector->project($v2)->toArray(), 'components', 'schemas', 'UsersAttributes', 'properties');

        self::assertSame(['name'], \array_keys($first));
        self::assertSame(['givenName', 'familyName'], \array_keys($second));
    }

    // ---- What the runtime actually does ---------------------------------------------

    /**
     * The document used to advertise `200` plus a `users` resource object here. The server
     * cannot resolve a serializer for a type it does not register, so the endpoint's own
     * first step raises the 500-status `NoResourceRegistered`.
     */
    #[Test]
    public function theRelatedEndpointCannotResolveTheSerializerItWouldNeed(): void
    {
        $server = $this->runtimeServer();

        try {
            $server->serializerFor('users');
            self::fail('Expected NoResourceRegistered.');
        } catch (\haddowg\JsonApi\Exception\NoResourceRegistered $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame('users', $e->type);
        }
    }

    /**
     * And the linkage the document promised never arrives either: with no serializer to
     * bind, the relationship is built links-only, so the resource document's `user`
     * carries no `data` member and the relationship endpoint answers with a linkage
     * document that has none — which the spec requires of it.
     */
    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function theLinkageComesBackWithNoDataMember(): void
    {
        $server = $this->runtimeServer();
        $favorites = $server->serializerFor('favorites');
        $favorite = ['id' => '1', 'label' => 'Best', 'user' => ['id' => '7', 'name' => 'Ada']];
        $request = new JsonApiRequest(new ServerRequest('GET', 'https://example.com/api/favorites/1'));

        $primary = $this->decode(DataResponse::fromResource($favorite, $favorites)->toPsrResponse($server, $request)->getBody()->__toString());
        $relationship = $this->arr($primary, 'data', 'relationships', 'user');
        self::assertArrayNotHasKey('data', $relationship);
        self::assertArrayHasKey('related', $this->arr($relationship, 'links'));

        $linkage = $this->decode(
            IdentifierResponse::forRelationship($favorite, $favorites, 'user')->toPsrResponse($server, $request)->getBody()->__toString(),
        );
        self::assertArrayNotHasKey('data', $linkage);
    }

    // ---- helpers --------------------------------------------------------------------

    private function runtimeServer(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withBaseUri('https://example.com/api')
            ->withPsr17($psr17, $psr17)
            ->register(CrossServerFavoriteResource::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    private function schemas(array $document): array
    {
        return $this->arr($document, 'components', 'schemas');
    }

    /**
     * @param array<array-key, mixed> $subject
     * @return array<array-key, mixed>
     */
    private function arr(array $subject, string ...$keys): array
    {
        $cursor = $subject;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }
        self::assertIsArray($cursor);

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $subject
     */
    private function str(array $subject, string ...$keys): string
    {
        $cursor = $subject;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }
        self::assertIsString($cursor);

        return $cursor;
    }
}

/**
 * The runtime half of the fixture: a resource whose `user` relation crosses the server
 * boundary, exactly as the music-catalog demo declares it.
 */
final class CrossServerFavoriteResource extends AbstractResource
{
    public static string $type = 'favorites';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            BelongsTo::make('user', 'users'),
        ];
    }
}
