<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog;

use haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryStore;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Album;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Artist;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Chart;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Favorite;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Library;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Playlist;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Track;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\User;

/**
 * Deterministic seed fixtures for the music catalog. {@see into()} populates a
 * fresh {@see InMemoryStore} with a coherent **object graph**: every relationship
 * is wired as a direct object reference (artists own albums, albums own tracks,
 * tracks belong to playlists, users own playlists + a library, and
 * favorites/libraries carry the related objects), so a read renders relationships
 * by reading the object straight off its parent — no foreign-key resolution.
 *
 * The graph is built in two passes: all objects are constructed first, then their
 * references are wired (so a back-reference such as album→artist and artist→albums
 * can point at the same instances).
 *
 * Counts are chosen to exercise the test surface: album "1" has three tracks so a
 * per-relation `perPage=2` paginator yields two pages; the slug/title/genre values
 * back the filter tests; the album date pair backs the directional CompareField.
 */
final class Seed
{
    public static function into(InMemoryStore $store): void
    {
        // --- Pass 1: construct every object (relationships unwired) -------------

        // Artists
        $radiohead = new Artist(
            id: '1',
            name: 'Radiohead',
            slug: 'radiohead',
            website: 'https://radiohead.com',
            bio: 'An English rock band formed in Abingdon.',
            trackCount: 3,
            createdAt: '2001-05-01T09:00:00+00:00',
        );
        $portishead = new Artist(
            id: '2',
            name: 'Portishead',
            slug: 'portishead',
            website: null,
            bio: null,
            trackCount: 1,
            createdAt: '2002-08-15T12:30:00+00:00',
        );

        // Albums
        $okComputer = new Album(
            id: '1',
            title: 'OK Computer',
            averageRating: 9.8,
            releasedAt: '1997-05-21T00:00:00+00:00',
            label: 'Parlophone',
            catalogueNumber: 'NODATA 01',
            explicit: false,
            availableFrom: '1997-05-21',
            availableUntil: '2030-12-31',
        );
        $dummy = new Album(
            id: '2',
            title: 'Dummy',
            averageRating: 9.1,
            releasedAt: '1994-08-22T00:00:00+00:00',
            label: 'Go! Beat',
            catalogueNumber: 'NODATA 02',
            explicit: false,
            availableFrom: '1994-08-22',
            availableUntil: '2031-01-01',
        );

        // Tracks
        $airbag = new Track(
            id: '1',
            title: 'Airbag',
            trackNumber: 1,
            length_seconds: 284,
            explicit: false,
            genres: ['rock', 'alternative'],
            previewOffset: '00:00:30',
        );
        $paranoidAndroid = new Track(
            id: '2',
            title: 'Paranoid Android',
            trackNumber: 2,
            length_seconds: 383,
            explicit: true,
            genres: ['rock', 'progressive'],
            previewOffset: '00:01:00',
        );
        $exitMusic = new Track(
            id: '3',
            title: 'Exit Music (For a Film)',
            trackNumber: 3,
            length_seconds: 264,
            explicit: false,
            genres: ['rock'],
            previewOffset: null,
        );
        $mysterons = new Track(
            id: '4',
            title: 'Mysterons',
            trackNumber: 1,
            length_seconds: 305,
            explicit: false,
            genres: ['trip-hop'],
            previewOffset: '00:00:45',
        );

        // Playlists (client-generated UUID ids)
        $morningMix = new Playlist(
            id: '00000000-0000-4000-8000-000000000001',
            title: 'Morning Mix',
            slug: 'morning-mix',
            public: true,
            externalId: '11111111-1111-4111-8111-111111111111',
        );

        // Users
        $ada = new User(
            id: '1',
            email: 'ada@example.com',
            displayName: 'Ada',
            birthDate: '1990-12-10',
            preferences: ['theme' => 'dark', 'autoplay' => true],
            lastSeenIp: '203.0.113.7',
            password: null,
        );

        // Favorites (MorphTo: the related object itself)
        $favTrack = new Favorite(id: '1', favoritedAt: '2024-01-15T10:00:00+00:00');
        $favAlbum = new Favorite(id: '2', favoritedAt: '2024-02-20T14:30:00+00:00');
        $favArtist = new Favorite(id: '3', favoritedAt: '2024-03-05T08:15:00+00:00');

        // Libraries (MorphToMany: a mixed list of related objects)
        $library = new Library(id: '1');

        // --- Pass 2: wire the object references --------------------------------

        // artist ↔ albums
        $radiohead->featuredAlbum = $okComputer;
        $radiohead->albums = [$okComputer];
        $portishead->featuredAlbum = null;
        $portishead->albums = [$dummy];
        $okComputer->artist = $radiohead;
        $dummy->artist = $portishead;

        // album ↔ tracks
        $okComputer->tracks = [$airbag, $paranoidAndroid, $exitMusic];
        $dummy->tracks = [$mysterons];
        $airbag->album = $okComputer;
        $paranoidAndroid->album = $okComputer;
        $exitMusic->album = $okComputer;
        $mysterons->album = $dummy;

        // track ↔ playlists (pivot)
        $morningMix->tracks = [$airbag, $paranoidAndroid];
        $airbag->playlists = [$morningMix];
        $paranoidAndroid->playlists = [$morningMix];

        // user ↔ playlists, user ↔ library
        $ada->playlists = [$morningMix];
        $ada->library = $library;
        $morningMix->owner = $ada;

        // favorites → favoritable (+ user)
        $favTrack->user = $ada;
        $favTrack->favoritable = $paranoidAndroid;
        $favAlbum->user = $ada;
        $favAlbum->favoritable = $okComputer;
        $favArtist->user = $ada;
        $favArtist->favoritable = $portishead;

        // library → owner + mixed items
        $library->owner = $ada;
        $library->items = [$airbag, $dummy, $radiohead];

        // --- Store everything --------------------------------------------------
        $store->put('artists', '1', $radiohead);
        $store->put('artists', '2', $portishead);
        $store->put('albums', '1', $okComputer);
        $store->put('albums', '2', $dummy);
        $store->put('tracks', '1', $airbag);
        $store->put('tracks', '2', $paranoidAndroid);
        $store->put('tracks', '3', $exitMusic);
        $store->put('tracks', '4', $mysterons);
        $store->put('playlists', '00000000-0000-4000-8000-000000000001', $morningMix);
        $store->put('users', '1', $ada);
        $store->put('favorites', '1', $favTrack);
        $store->put('favorites', '2', $favAlbum);
        $store->put('favorites', '3', $favArtist);
        $store->put('libraries', '1', $library);

        // --- Charts (standalone bare serializer, read-only) --------------------
        $weeklyTop = new Chart(
            id: '1',
            name: 'Weekly Top',
            period: '2024-W03',
            entries: [
                ['rank' => 1, 'trackId' => '2', 'plays' => 12000],
                ['rank' => 2, 'trackId' => '1', 'plays' => 9800],
                ['rank' => 3, 'trackId' => '4', 'plays' => 7100],
            ],
        );
        $store->put('charts', '1', $weeklyTop);
    }
}
