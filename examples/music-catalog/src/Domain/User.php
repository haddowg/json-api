<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A user. `password` is write-only (serialized as null); `preferences` is an
 * `ArrayHash` of dynamic keys.
 *
 * Relationships are held as the **related objects**: `$playlists` is a list of
 * {@see Playlist}s and `$library` a {@see Library} (or null), read straight off
 * the object by the default relation reader.
 */
final class User
{
    /**
     * @param array<string, mixed> $preferences
     * @param list<Playlist>       $playlists
     */
    public function __construct(
        public string $id = '',
        public string $email = '',
        public string $displayName = '',
        public ?string $birthDate = null,
        public array $preferences = [],
        public ?string $lastSeenIp = null,
        public ?string $password = null,
        public array $playlists = [],
        public ?Library $library = null,
    ) {}
}
