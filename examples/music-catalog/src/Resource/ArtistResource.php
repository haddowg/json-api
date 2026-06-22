<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Artist;
use haddowg\JsonApi\Examples\MusicCatalog\Sort\TrackCountSort;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * The `artists` resource type.
 *
 * Demonstrates: a singular filter (`filter[slug]` collapses zero-to-one), a
 * computed read-only attribute (`trackCount`) backing a custom
 * {@see TrackCountSort}, create-vs-update read-only scoping
 * (`createdAt->readOnlyOnUpdate()`), and the two `hasOne`/`hasMany` relation
 * shapes with load-aware to-many linkage.
 */
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(120)->sortable(),
            Str::make('slug')->sortable(),
            Url::make('website')->nullable(),
            Str::make('bio')->nullable()->maxLength(1000),
            // Computed: no backing column (computed() nulls it), so read the
            // value off the domain object's trackCount property explicitly. It
            // backs the custom TrackCountSort registered in sorts().
            Integer::make('trackCount')
                ->computed()
                ->readOnly()
                ->extractUsing(static fn(mixed $artist): int => $artist instanceof Artist ? $artist->trackCount : 0),
            DateTime::make('createdAt')->readOnlyOnUpdate()->sortable(),

            // Default relation reader: `featuredAlbum` reads $artist->featuredAlbum
            // (an Album, or null) and `albums` reads $artist->albums (a list<Album>).
            HasOne::make('featuredAlbum', 'albums'),
            HasMany::make('albums', 'albums'),
        ];
    }

    public function filters(): array
    {
        // singular(): GET /artists?filter[slug]=radiohead collapses a unique
        // match to a single resource object (or null), not a collection.
        return [
            Where::make('slug')->singular(),
        ];
    }

    public function sorts(): array
    {
        // A computed sort: ordering by trackCount has no single sortable column,
        // so it is expressed as a custom SortInterface the CriteriaApplier
        // executes (never the ArraySortHandler).
        return [
            new TrackCountSort(),
        ];
    }

    /**
     * Object-aware so this resource can participate in a polymorphic relation
     * (favorites→favoritable, libraries→items): a resolver probing it with a
     * foreign member must not have it falsely claim that member as an `artists`
     * resource. Only a real {@see Artist} is an `artists` type.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof Artist ? 'artists' : '';
    }
}
