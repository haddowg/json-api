<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Serializer;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Track;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;

/**
 * A hand-written read serializer for the `tracks` type — the full
 * serializer escape hatch (reach for it LAST, after a field's
 * `serializeUsing()`/`extractUsing()`). It is registered as a **read override**
 * via `Server::register(TrackResource::class, serializer: TrackSerializer::class)`:
 * it wins for serialization while {@see \haddowg\JsonApi\Examples\MusicCatalog\Resource\TrackResource}
 * still hydrates writes — proving read and write capabilities are independently
 * resolvable.
 *
 * It exercises the three "reach for a custom serializer" triggers:
 *  - a **request-aware** attribute (`nowPlaying`) — present only when the request
 *    carries an authenticated `user` attribute (the membership view), absent
 *    otherwise, so the attribute *set* itself is request-dependent;
 *  - a **computed/derived** attribute (`displayTitle`) assembled across the
 *    `trackNumber` + `title` columns purely on read.
 *
 * The override constraint (per the docs): an override serializer is instantiated
 * with `new` — no constructor arguments. Relationship rendering is therefore *not*
 * available by default; this serializer opts in via
 * {@see SerializerResolverAwareInterface} so the registry injects the
 * {@see SerializerResolverInterface} after construction, letting it render the
 * `album` and `playlists` relationships through the shared
 * {@see RendersRelationsTrait} — the same relation declarations the resource uses.
 *
 * {@see getDefaultIncludedRelationships()} returns `[]`, witnessing that the
 * default-include lever lives on the 7-method serializer contract (an override
 * could return `['album']` to default-include without any fluent field method).
 */
final class TrackSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $serializerResolver = null;

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->serializerResolver = $resolver;
    }

    public function getType(mixed $object): string
    {
        // Object-aware so a polymorphic resolver (MorphTo/MorphToMany) that probes
        // this serializer with a foreign member does not have it falsely claim the
        // member as a `tracks` resource: only a real Track is a `tracks` type.
        return $object instanceof Track ? 'tracks' : '';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Track);

        return $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        $attributes = [
            'title' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
                => $track instanceof Track ? $track->title : '',
            'trackNumber' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): int
                => $track instanceof Track ? $track->trackNumber : 0,
            'durationSeconds' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): int
                => $track instanceof Track ? $track->length_seconds : 0,
            'explicit' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): bool
                => $track instanceof Track && $track->explicit,
            'genres' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): array
                => $track instanceof Track ? $track->genres : [],
            'previewOffset' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): ?string
                => $track instanceof Track ? $track->previewOffset : null,
            // Computed across two columns purely on read — the cheapest reason to
            // hand-write a serializer made explicit.
            'displayTitle' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
                => $track instanceof Track ? \sprintf('%d. %s', $track->trackNumber, $track->title) : '',
        ];

        // Request-aware: the `nowPlaying` attribute exists ONLY for an
        // authenticated user (a request attribute set by the host's auth layer).
        // The attribute *set* is request-dependent — anonymous responses omit it.
        if ($request->getAttribute('user') !== null) {
            $attributes['nowPlaying'] = static fn(mixed $track, JsonApiRequestInterface $request, string $field): bool
                => $track instanceof Track && $request->getAttribute('nowPlayingTrackId') === $track->id;
        }

        return $attributes;
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $resolver = $this->serializerResolver;
        if ($resolver === null) {
            return [];
        }

        return self::relationshipCallables($this->relations(), $resolver);
    }

    /**
     * The relation fields this serializer renders — the same declarations the
     * resource makes for `album` and `playlists`. Built per call (the contract is
     * stateless), so one instance safely serializes many tracks.
     *
     * @return list<RelationInterface>
     */
    private function relations(): array
    {
        return [
            // The same default-reader declarations the resource makes: `album` reads
            // $track->album and `playlists` reads $track->playlists straight off the
            // object — no extractor.
            BelongsTo::make('album', 'albums'),
            BelongsToMany::make('playlists', 'playlists')
                ->fields(
                    Integer::make('position')->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
                ->cannotReplace(),
        ];
    }
}
