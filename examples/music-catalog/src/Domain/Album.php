<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * An album. The `releaseInfo` Map field spreads across the flat `label` and
 * `catalogueNumber` columns; the `availableFrom`/`availableUntil` pair backs the
 * directional `CompareField` (availableUntil GreaterThan availableFrom).
 *
 * Relationships are held as the **related objects**: `$artist` is an {@see Artist}
 * (or null) and `$tracks` a list of {@see Track}s, so the default relation reader
 * returns them straight off the object — no foreign-key columns, no extractor.
 */
final class Album
{
    /**
     * @param list<Track> $tracks
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?float $averageRating = null,
        public ?string $releasedAt = null,
        public ?string $label = null,
        public ?string $catalogueNumber = null,
        public bool $explicit = false,
        public ?string $availableFrom = null,
        public ?string $availableUntil = null,
        public ?Artist $artist = null,
        public array $tracks = [],
    ) {}
}
