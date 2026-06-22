<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Favorite;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;

/**
 * The `favorites` resource. Demonstrates a polymorphic to-one
 * ({@see MorphTo}): `favoritable` points at a track, album, or artist, and the
 * member's serializer is resolved at runtime from the related object's own type
 * (see {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()}).
 * The related value is read off the resolved `$favoritable` domain object the
 * repository hydrates onto the {@see Favorite}; an empty to-one renders
 * `data: null`.
 */
final class FavoriteResource extends AbstractResource
{
    public static string $type = 'favorites';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('favoritedAt')->readOnlyOnUpdate(),

            // Default relation reader: `user` reads $favorite->user (a User).
            BelongsTo::make('user', 'users'),
            // THE escape hatch (the one and only extractUsing in the example): a
            // custom relation resolver, kept here precisely because a polymorphic
            // to-one is the natural place to need one — when the related value is not
            // a plain stored property but is *derived* (here, picked off the
            // discriminated `favoritable` member). The default reader would do for
            // this exact property, but this demonstrates the hook you reach for when
            // it would not. This is the exception, not the norm — every other
            // relation in the example uses the default reader.
            MorphTo::make('favoritable', ['tracks', 'albums', 'artists'])
                ->extractUsing(static fn(mixed $favorite): ?object => $favorite instanceof Favorite ? $favorite->favoritable : null),
        ];
    }
}
