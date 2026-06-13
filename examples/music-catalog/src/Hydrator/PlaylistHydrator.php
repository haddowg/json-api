<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Hydrator;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Playlist;
use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A hand-written hydrator for the `playlists` type — the full hydrator escape
 * hatch. It is registered as a **write override** via
 * `Server::register(PlaylistResource::class, hydrator: PlaylistHydrator::class)`:
 * it wins for writes (POST/PATCH and relationship mutation) while
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Resource\PlaylistResource} still
 * serializes reads — read and write capabilities resolved independently.
 *
 * Extending {@see AbstractHydrator} gives the method-dispatched create/update path
 * (POST → create, PATCH → update), the `UpdateRelationshipHydratorInterface`
 * relationship-endpoint write path, and the {@see validateDomainObject()}
 * post-hydration seam — leaving this class to declare only:
 *
 *  - {@see getAttributeHydrator()} — the headline reason to hand-write a hydrator:
 *    one client member (`title`) fans out to **two** stored columns, deriving the
 *    read-only `slug` from the normalised title (a value the field DSL never lets
 *    the client set);
 *  - the client-generated-id policy ({@see validateClientGeneratedId()} accepts a
 *    client UUID, matching the resource's `acceptsClientGeneratedId()`);
 *  - {@see validateDomainObject()} — a cross-field business rule checked after the
 *    object is fully hydrated.
 *
 * It declares **no** relationship hydrator ({@see getRelationshipHydrator()}
 * returns `[]`): in this object-graph example a relationship write resolves a
 * linkage id to the stored related **object** before setting the parent's
 * reference, which needs the store — a capability the {@see MusicCatalogHandler}
 * owns. So the handler applies relationships (for both whole-resource writes and
 * `/relationships/{rel}` endpoint mutations); the hydrator handles only the id and
 * attributes.
 */
final class PlaylistHydrator extends AbstractHydrator
{
    protected function getAcceptedTypes(): array
    {
        return ['playlists'];
    }

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        return [
            // The fan-out: one `title` member fills the title AND derives the
            // read-only `slug`. A field-DSL resource cannot express "set one
            // column from another" — this is why you hand-write a hydrator.
            'title' => static function (mixed $playlist, mixed $value, array $data, string $field): Playlist {
                \assert($playlist instanceof Playlist);
                $title = \is_string($value) ? \trim($value) : '';
                $playlist->title = $title;
                $playlist->slug = self::slugify($title);

                return $playlist;
            },
            'public' => static function (mixed $playlist, mixed $value, array $data, string $field): Playlist {
                \assert($playlist instanceof Playlist);
                $playlist->public = (bool) $value;

                return $playlist;
            },
            'externalId' => static function (mixed $playlist, mixed $value, array $data, string $field): Playlist {
                \assert($playlist instanceof Playlist);
                $playlist->externalId = \is_string($value) && $value !== '' ? $value : null;

                return $playlist;
            },
        ];
    }

    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        // No relationship hydration here: the handler owns it, because resolving a
        // linkage id to the stored related object (to set the parent's object
        // reference) needs the store the hydrator has no access to.
        return [];
    }

    /**
     * The resource opts in to client-generated ids
     * (`PlaylistResource::acceptsClientGeneratedId()` returns true), so a client
     * UUID is accepted and never rejected here. A type that did not accept one
     * would throw {@see \haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported}.
     */
    protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void
    {
        // Accepted: no-op.
    }

    protected function validateRequest(JsonApiRequestInterface $request): void
    {
        // No request-level pre-checks for playlists.
    }

    protected function generateId(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        \assert($domainObject instanceof Playlist);
        $domainObject->id = $id;

        return $domainObject;
    }

    /**
     * The post-hydration seam: a cross-field business rule the field DSL cannot
     * express, checked once the object is fully built. A title is mandatory, so a
     * derived slug must be non-empty.
     */
    protected function validateDomainObject(JsonApiRequestInterface $request, mixed $domainObject): void
    {
        \assert($domainObject instanceof Playlist);

        if ($domainObject->title !== '' && $domainObject->slug === '') {
            throw new \LogicException('A titled playlist must have a derived slug.');
        }
    }

    /**
     * Lower-cases, strips non-alphanumerics to single hyphens, and trims — a
     * minimal slugifier (not production-grade transliteration).
     */
    private static function slugify(string $title): string
    {
        $slug = \strtolower($title);
        $slug = \preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return \trim($slug, '-');
    }
}
