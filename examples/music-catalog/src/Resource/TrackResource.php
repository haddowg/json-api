<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Track;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

/**
 * The `tracks` resource type.
 *
 * Demonstrates: an `ArrayList` with per-item rules
 * (`genres->minItems(1)->each(...)->uniqueItems()`); a `storedAs()` column rename
 * (`durationSeconds` ← `length_seconds`); a computed read-only attribute
 * (`displayTitle`) derived across columns via `extractUsing()`; a `like` text
 * filter on `title`; a `belongsTo` to-one (`album`) and a pivot-backed
 * `belongsToMany` to-many (`playlists`) that prohibits full replacement
 * (`cannotReplace()`).
 *
 * It is also the override-resolution witness: the server registers it with a
 * custom {@see \haddowg\JsonApi\Examples\MusicCatalog\Serializer\TrackSerializer}
 * (the serializer wins for reads — request-aware `nowPlaying` + `displayTitle`)
 * while this resource still hydrates writes.
 */
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            Integer::make('trackNumber')->min(1)->sortable(),
            // The JSON:API member `durationSeconds` is stored on the domain
            // object's `length_seconds` column — a storedAs() rename round-trips
            // transparently through both serialize and hydrate.
            Integer::make('durationSeconds')->storedAs('length_seconds'),
            Boolean::make('explicit'),
            // An array attribute with per-item rules: at least one genre, each a
            // non-empty string, no duplicates.
            ArrayList::make('genres')
                ->minItems(1)
                ->each(new MinLength(1))
                ->uniqueItems(),
            Time::make('previewOffset')->nullable(),
            // Computed: no backing column (computed() nulls it), derived across the
            // trackNumber + title columns purely on read.
            Str::make('displayTitle')
                ->computed()
                ->readOnly()
                ->extractUsing(static fn(mixed $track): string => $track instanceof Track
                    ? \sprintf('%d. %s', $track->trackNumber, $track->title)
                    : ''),

            // Default relation reader: `album` reads $track->album (an Album, or
            // null) straight off the object — no foreign-key column, no extractor.
            BelongsTo::make('album')->type('albums'),
            // A pivot-backed to-many reading $track->playlists (a list<Playlist>).
            // The pivot fields are real field definitions — a writable `position`
            // and a server-owned, read-only `addedAt`; cannotReplace() rejects a
            // PATCH to the relationship endpoint with 403 FullReplacementProhibited
            // (add/remove still allowed).
            BelongsToMany::make('playlists')
                ->type('playlists')
                ->fields(
                    Integer::make('position')->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
                ->cannotReplace(),
        ];
    }

    public function filters(): array
    {
        // `like`: a case-insensitive substring match on title (the operator is the
        // third make() argument — there is no fluent operator() setter).
        // `explicit` coerces the request value to a real bool and defaults to false
        // when the key is absent. `genres` matches a membership set.
        return [
            Where::make('title', 'title', 'like'),
            Where::make('explicit')->asBoolean()->default(false),
            WhereIn::make('genres'),
        ];
    }
}
