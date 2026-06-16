<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A track. `length_seconds` is the storage column behind the `durationSeconds`
 * field (a `storedAs()` rename); `genres` is an `ArrayList`.
 *
 * Relationships are held as the **related objects**: `$album` is an {@see Album}
 * (or null) and `$playlists` a list of {@see Playlist}s — the to-many side of the
 * tracks↔playlists pivot. The pivot's `position`/`addedAt` fields are declare-only
 * metadata in 1.0, so the object list alone backs the relation.
 */
final class Track
{
    /**
     * @param list<string>   $genres
     * @param list<Playlist> $playlists
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public int $trackNumber = 0,
        public int $length_seconds = 0,
        public bool $explicit = false,
        public array $genres = [],
        public ?string $previewOffset = null,
        public ?Album $album = null,
        public array $playlists = [],
    ) {}
}
