<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A recording artist. A plain mutable domain object — no base class, no ORM,
 * no annotations. The {@see \haddowg\JsonApi\Examples\MusicCatalog\Resource\ArtistResource}
 * field column names match these property names exactly.
 *
 * Relationships are held as the **related objects** themselves — `$featuredAlbum`
 * is an {@see Album} (or null), `$albums` a list of {@see Album}s — so the default
 * relation reader (`Accessor::get($artist, 'featuredAlbum')` /
 * `…, 'albums'`) returns them with no extractor.
 */
final class Artist
{
    /**
     * @param list<Album> $albums
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $slug = '',
        public ?string $website = null,
        public ?string $bio = null,
        public int $trackCount = 0,
        public string $createdAt = '',
        public ?Album $featuredAlbum = null,
        public array $albums = [],
    ) {}
}
