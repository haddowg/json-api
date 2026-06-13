<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A favorite: a polymorphic to-one (`MorphTo`) pointing at a track, album, or
 * artist. `$user` is the related {@see User} object; `$favoritable` is the related
 * domain object (a {@see Track}, {@see Album}, or {@see Artist}) — the member whose
 * serializer the `MorphTo` relation resolves from the object's own type.
 */
final class Favorite
{
    public function __construct(
        public string $id = '',
        public string $favoritedAt = '',
        public ?User $user = null,
        public ?object $favoritable = null,
    ) {}
}
