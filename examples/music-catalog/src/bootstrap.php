<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog;

use haddowg\JsonApi\Examples\MusicCatalog\Data\CriteriaApplier;
use haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryRelationshipCount;
use haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryRepository;
use haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryStore;
use haddowg\JsonApi\Examples\MusicCatalog\Handler\MusicCatalogHandler;
use haddowg\JsonApi\Examples\MusicCatalog\Http\PathPrefixRouter;
use haddowg\JsonApi\Examples\MusicCatalog\Hydrator\PlaylistHydrator;
use haddowg\JsonApi\Examples\MusicCatalog\Profile\TimestampProfile;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\AlbumResource;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\ArtistResource;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\FavoriteResource;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\LibraryResource;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\PlaylistResource;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\TrackResource;
use haddowg\JsonApi\Examples\MusicCatalog\Resource\UserResource;
use haddowg\JsonApi\Examples\MusicCatalog\Serializer\ChartSerializer;
use haddowg\JsonApi\Examples\MusicCatalog\Serializer\TrackSerializer;
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The pinned public entry point for the music-catalog example app.
 *
 * Every test boots through here, and every "wire a Server" docs snippet is
 * extracted from this function — so it is the single source of truth for the
 * assembly story: a {@see Server} built fluently (immutable, every `with…()`
 * returns a new instance), the seven Resources registered by **class-string** key,
 * the standalone read-only `charts` type registered by **type-string** key (a bare
 * serializer with no Resource, no hydrator), one custom profile + the bundled
 * cursor profile, a default paginator, and the recommended middleware chain wrapped
 * around the {@see MusicCatalogHandler}.
 */

/**
 * Boots a fully-wired, seeded {@see Server} ready to serve `handle()` / `dispatch()`.
 */
function bootstrap(bool $debug = false): Server
{
    $store = new InMemoryStore();
    Seed::into($store);

    [$server] = assemble($store, $debug);

    return $server;
}

/**
 * Boots over a caller-supplied {@see InMemoryStore} and returns both the
 * {@see Server} and the {@see InMemoryRepository} backing it, so a test can seed /
 * inspect the store and assert that a create is immediately readable (read and
 * write share one store).
 *
 * @return array{0: Server, 1: InMemoryRepository}
 */
function bootstrapWithRepository(InMemoryStore $store, bool $debug = false): array
{
    return assemble($store, $debug);
}

/**
 * The shared assembly used by both entry points: build the repository over the
 * store, build the configured Server, wrap it in the middleware chain + handler.
 *
 * @return array{0: Server, 1: InMemoryRepository}
 */
function assemble(InMemoryStore $store, bool $debug): array
{
    $psr17 = new Psr17Factory();
    $repository = new InMemoryRepository($store, new CriteriaApplier());

    // Step 1: the configuration root — base URI, PSR-17 factories, a default
    // paginator, and the recognised profiles. Every `with…()` returns a new
    // immutable Server.
    $base = Server::make()
        ->withBaseUri('https://music.example')
        ->withPsr17($psr17, $psr17)
        // The default paginator caps page[size] at 50 here (above the 10-per-page
        // default, so the default is untouched): an over-large page[size] is
        // clamped to the cap, not honoured. PagePaginator caps at 100 out of the
        // box; withMaxPerPage(0) would disable the cap entirely.
        ->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(10)->withMaxPerPage(50))
        // The count seam: supplies the `meta.total` core renders on a relationship
        // object when a request names a countable() to-many relation in `?withCount`
        // (e.g. GET /albums/1?withCount=tracks). With none injected, no count is
        // emitted even for a countable relation.
        ->withRelationshipCount(new InMemoryRelationshipCount())
        ->withProfile(new TimestampProfile())
        ->withProfile(new CursorPaginationProfile())
        ->withProfile(new CountableProfile())
        // Step 2: register the seven Resources by CLASS-STRING key. Two carry an
        // override: tracks override the *serializer* (a request-aware TrackSerializer
        // wins for reads; TrackResource still hydrates), playlists override the
        // *hydrator* (PlaylistHydrator wins for writes; PlaylistResource still
        // serializes).
        ->register(ArtistResource::class)
        ->register(AlbumResource::class)
        ->register(TrackResource::class, serializer: TrackSerializer::class)
        ->register(PlaylistResource::class, hydrator: PlaylistHydrator::class)
        ->register(UserResource::class)
        ->register(FavoriteResource::class)
        ->register(LibraryResource::class)
        // Step 3: the standalone read-only `charts` type by TYPE-STRING key — a bare
        // serializer with no Resource and no hydrator (so `hasHydratorFor('charts')`
        // is false and `resourceFor('charts')` throws NoResourceRegistered). The
        // router routes only GET for it (operation allow-list = fetch only).
        ->registerSerializerHydrator('charts', serializer: ChartSerializer::class);

    // Step 4: the recommended middleware chain (outermost first) wrapped around the
    // operation handler. ErrorHandler must be outermost so it catches negotiation,
    // body-parsing and handler throwables; the router runs last, attaching the
    // routing Target the adapter reads.
    $server = $base
        ->withMiddleware([
            new ErrorHandlerMiddleware($base, $debug),
            new ContentNegotiationMiddleware(),
            new RequestBodyParsingMiddleware(),
            new PathPrefixRouter($base),
        ])
        ->withHandler(new MusicCatalogHandler($repository));

    return [$server, $repository];
}
