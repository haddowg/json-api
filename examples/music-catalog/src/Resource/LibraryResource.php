<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;

/**
 * The `libraries` resource. Demonstrates a polymorphic to-many
 * ({@see MorphToMany}): `items` is a mixed collection of tracks, albums, and
 * artists, rendered through a
 * {@see \haddowg\JsonApi\Serializer\PolymorphicSerializer} that resolves and
 * delegates to the per-member serializer (a member matching no declared type
 * throws a `\LogicException`). The mixed members are read off the resolved
 * `$items` list the repository hydrates onto the library; the in-memory provider
 * supports the mixed-collection read, but the polymorphic to-many carries no
 * shared filter/sort vocabulary (those `400`; `page` slices).
 */
final class LibraryResource extends AbstractResource
{
    public static string $type = 'libraries';

    public function fields(): array
    {
        return [
            Id::make(),

            // Default relation reader: `owner` reads $library->owner (a User) and
            // `items` reads $library->items (the mixed related list) straight off the
            // object — the polymorphic members are already objects, so no extractor
            // is needed; each renders through its own per-type serializer.
            BelongsTo::make('owner', 'users'),
            MorphToMany::make('items', ['tracks', 'albums', 'artists']),
        ];
    }
}
