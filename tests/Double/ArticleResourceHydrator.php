<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Resource\ResourceInterface;

/**
 * Composition-contract proof fixture.
 *
 * Implements both {@see ResourceInterface} (serializer) and
 * {@see HydratorInterface} (request→domain) **directly**, with NO inheritance
 * from AbstractResource or AbstractHydrator. Proves both contracts are
 * implementable purely by composition.
 *
 * ResourceInterface passes the domain object and request as explicit parameters
 * to every method, so no per-pass state storage is required. The two @internal
 * lifecycle methods ({@see initializeTransformation}/{@see clearTransformation})
 * are therefore no-ops here — a valid implementation when the resource does not
 * need to cache cross-method state.
 */
final class ArticleResourceHydrator implements ResourceInterface, HydratorInterface
{
    // -------------------------------------------------------------------------
    // ResourceInterface — serializer side
    // -------------------------------------------------------------------------

    public function getType(mixed $object): string
    {
        return 'articles';
    }

    public function getId(mixed $object): string
    {
        if ($object instanceof Article) {
            return $object->id;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(mixed $object): array
    {
        return [];
    }

    public function getLinks(mixed $object): ?ResourceLinks
    {
        return null;
    }

    /**
     * Returns attribute callables.
     *
     * Each callable receives (mixed $domainObject, JsonApiRequestInterface $request, string $attributeName)
     * and returns the attribute value. The closure captures no external state —
     * the domain object is passed in by the transformer at call time.
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed>
     */
    public function getAttributes(mixed $object): array
    {
        return [
            'title' => static function (mixed $obj): mixed {
                return $obj instanceof Article ? $obj->title : null;
            },
            'authorId' => static function (mixed $obj): mixed {
                return $obj instanceof Article ? $obj->authorId : null;
            },
        ];
    }

    /**
     * @return list<string>
     */
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    /**
     * Returns relationship callables.
     *
     * Returning an empty map here keeps the fixture focused: this class has no
     * associated resource types to relate to, so there are no relationships to
     * describe. The callable signature is callable(mixed, JsonApiRequestInterface,
     * string): AbstractRelationship.
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship>
     */
    public function getRelationships(mixed $object): array
    {
        return [];
    }

    /**
     * @internal No cross-method state needed: every ResourceInterface method receives
     * the domain object and request as explicit parameters.
     */
    public function initializeTransformation(JsonApiRequestInterface $request, mixed $object): void {}

    /**
     * @internal No cross-method state to clear.
     */
    public function clearTransformation(): void {}

    // -------------------------------------------------------------------------
    // HydratorInterface — request→domain side
    // -------------------------------------------------------------------------

    /**
     * Hydrates a POST create body into an {@see Article}.
     *
     * Reads the resource type, validates it, then extracts attributes directly
     * from the request. No ExceptionFactory, no traits, no AbstractHydrator —
     * everything is implemented inline.
     *
     * @param mixed $domainObject Ignored on create; a fresh Article is returned.
     * @return Article
     *
     * @throws ResourceTypeMissing|ResourceTypeUnacceptable
     */
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $type = $request->getResourceType();

        if ($type === null || $type === '') {
            throw new ResourceTypeMissing();
        }

        if (\is_string($type) === false || $type !== 'articles') {
            throw new ResourceTypeUnacceptable(
                \is_string($type) ? $type : \gettype($type),
                ['articles'],
            );
        }

        $attributes = $request->getResourceAttributes();
        $title = isset($attributes['title']) && \is_string($attributes['title'])
            ? $attributes['title']
            : '';

        $authorId = isset($attributes['authorId']) && \is_string($attributes['authorId'])
            ? $attributes['authorId']
            : null;

        // Use the domain object's existing ID when updating; generate one when creating.
        $id = \is_string($request->getResourceId()) && $request->getResourceId() !== ''
            ? $request->getResourceId()
            : 'generated-1';

        return new Article($id, $title, $authorId);
    }
}
