<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Uuid;

/**
 * The `playlists` resource type.
 *
 * Demonstrates: a client-generated UUID id (`Id::make()->uuid()->allowClientId()`,
 * so a `POST` may supply its own id — the custom {@see PlaylistHydrator} that wins
 * for writes accepts it too); a read-only `slug` derived from `title` by the custom
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Hydrator\PlaylistHydrator} (never
 * client-written); a `belongsTo` owner; and a pivot-backed `belongsToMany`
 * `tracks` whose related collection paginates two-per-page.
 *
 * The server registers it with that custom hydrator (the hydrator wins for
 * writes) while this resource still serializes reads.
 */
final class PlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            // A client-generated UUID id: allowClientId() opts in so a POST may
            // carry its own `id` (a default resource rejects one).
            Id::make()->uuid()->allowClientId(),
            Str::make('title')->required(),
            // Derived from title by the custom hydrator, so read-only on the wire.
            Slug::make('slug')->readOnly(),
            Boolean::make('public'),
            Uuid::make('externalId')->nullable(),

            // Default relation reader: `owner` reads $playlist->owner (a User) and
            // `tracks` reads $playlist->tracks (a list<Track>).
            BelongsTo::make('owner')->type('users'),
            // A pivot-backed to-many whose related-collection endpoint paginates
            // two-per-page (the pivot fields are declare-only metadata in 1.0).
            BelongsToMany::make('tracks')
                ->type('tracks')
                ->fields(['position' => 'integer', 'addedAt' => 'datetime'])
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
        ];
    }
}
