<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A playlist. Its `id` is a client-generated UUID (the resource opts in via
 * `Id::make()->uuid()->allowClientId()`); `slug` is derived from `title` by the custom
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Hydrator\PlaylistHydrator}.
 *
 * Relationships are held as the **related objects**: `$owner` is a {@see User}
 * (or null) and `$tracks` a list of {@see Track}s — the other side of the
 * tracks↔playlists pivot.
 */
final class Playlist
{
    /**
     * @param list<Track> $tracks
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $slug = '',
        public bool $public = false,
        public ?string $externalId = null,
        public ?User $owner = null,
        public array $tracks = [],
    ) {}
}
