<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Album;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * The `albums` resource type.
 *
 * Demonstrates three headline levers:
 *
 * 1. **Default includes by override** — {@see getDefaultIncludedRelationships()}
 *    returns `['artist']`, so `GET /albums/1` (with no `?include`) emits the
 *    artist in `included`. There is no fluent "include by default" field method;
 *    the lever lives on the serializer contract.
 * 2. **A directional `CompareField`** — `availableUntil->compareWith('availableFrom',
 *    GreaterThan)` reads `availableUntil > availableFrom`, i.e. this field on the
 *    LEFT (contrast the users password equality which is non-directional).
 * 3. **A `Map` with a read-only child** — `releaseInfo` spreads across the flat
 *    `label` / `catalogueNumber` columns; the `catalogueNumber` child is
 *    `readOnly()`, so a write through the nested object is ignored and a child
 *    violation surfaces as a `/data/attributes/releaseInfo/<child>` pointer.
 */
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            // The app mints a v4 UUID when no client id is supplied (a client id is
            // still rejected — albums does not opt in). Contrast PlaylistResource,
            // which accepts a client UUID.
            Id::make()->uuid()->generated(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Decimal::make('averageRating')->readOnly()->nullable(),
            // Closure bound: no future release dates. Resolved at validation time
            // (so it reflects "now" per request) and does not round-trip to JSON
            // Schema. Hydrated values are normalised to UTC.
            DateTime::make('releasedAt')
                ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
                ->useTimezone('UTC')
                ->sortable(),
            // The Map's children read/write FLAT domain columns (label,
            // catalogueNumber); catalogueNumber is readOnly so it never hydrates.
            Map::make('releaseInfo')->fields(
                Str::make('label'),
                Str::make('catalogueNumber')->readOnly(),
            ),
            Boolean::make('explicit'),
            Date::make('availableFrom')->nullable(),
            // CORRECTION 2: directional CompareField. The operator reads
            // `availableUntil > availableFrom` — this field is the LEFT operand.
            Date::make('availableUntil')
                ->nullable()
                ->compareWith('availableFrom', Comparison::GreaterThan),

            // The default relation reader: the relationship name equals the domain
            // property holding the related object(s). `artist` reads $album->artist
            // (an Artist) and `tracks` reads $album->tracks (a list<Track>) — no
            // extractUsing, no foreign-key column. This is the pattern to teach.
            BelongsTo::make('artist', 'artists'),
            HasMany::make('tracks', 'tracks')
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                // (A to-many is lazy by default — links-only until loaded/included —
                // so `tracks` needs no opt-in to defer its linkage.)
                // Countable: when a request names `tracks` in `?withCount`, the
                // relationship object carries `meta.total` (the related-collection
                // cardinality) — e.g. GET /albums/1?withCount=tracks → 3.
                ->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            // WhereHas('tracks'): albums that have at least one related track. The
            // relationship key reads $album->tracks directly (the Doctrine reference
            // would render an EXISTS subquery over the same relation).
            WhereHas::make('tracks'),
            // WhereThrough('artist.name'): a dotted-path EXISTS-ANY semi-join —
            // keeps albums whose artist's name matches. Responds to
            // `filter[artist.name]=Radiohead`; traverses album->artist->name without
            // a fetch-join (the Doctrine reference renders a correlated EXISTS).
            WhereThrough::make('artist.name'),
        ];
    }

    /**
     * Newest first by default. With no `?sort` the albums collection is ordered by
     * `releasedAt` descending (so OK Computer (1997) precedes Dummy (1994)),
     * keeping the unsorted collection — and its pagination — deterministic. An
     * explicit `?sort=…` overrides this entirely.
     *
     * @return list<SortDirective>
     */
    public function defaultSort(): array
    {
        return [
            new SortDirective(SortByField::make('releasedAt'), descending: true),
        ];
    }

    /**
     * CORRECTION 1: default includes are realised purely as this override —
     * there is no field/relation fluent method for it. `GET /albums/1` with no
     * `?include` yields the artist in `included`; an explicit `?include`
     * suppresses this default.
     *
     * @return list<string>
     */
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['artist'];
    }

    /**
     * Object-aware so this resource can participate in a polymorphic relation
     * (favorites→favoritable, libraries→items): a resolver probing it with a
     * foreign member must not have it falsely claim that member as an `albums`
     * resource. Only a real {@see Album} is an `albums` type.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof Album ? 'albums' : '';
    }
}
