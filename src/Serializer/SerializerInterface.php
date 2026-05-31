<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * Per-resource-type serializer: maps a domain object to its JSON:API resource
 * representation (type, id, meta, links, attributes, relationships).
 *
 * This is a consumer extension point — the primary way to describe how a domain
 * value becomes a JSON:API resource. Implement directly or extend
 * {@see AbstractSerializer}. The serialized value is `mixed`: a resource may
 * describe an object, an array, or any domain representation, so no generic
 * type parameter is imposed.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#document-resource-objects
 */
interface SerializerInterface
{
    /**
     * Provides the "type" member of the resource.
     */
    public function getType(mixed $object): string;

    /**
     * Provides the "id" member of the resource.
     */
    public function getId(mixed $object): string;

    /**
     * Provides the "meta" member of the resource.
     *
     * Returns an array of non-standard meta information about the resource. An
     * empty array omits the member from the response.
     *
     * @return array<string, mixed>
     */
    public function getMeta(mixed $object): array;

    /**
     * Provides the "links" member of the resource.
     *
     * Returns a {@see ResourceLinks} object to provide linkage about the
     * resource, or null to omit the member.
     */
    public function getLinks(mixed $object): ?ResourceLinks;

    /**
     * Provides the "attributes" member of the resource.
     *
     * Returns a map keyed by attribute name; each value is a callable receiving
     * the domain object (plus the active request and attribute name) and
     * returning the attribute value.
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed>
     */
    public function getAttributes(mixed $object): array;

    /**
     * Returns the relationship names included in the response by default.
     *
     * @return list<string>
     */
    public function getDefaultIncludedRelationships(mixed $object): array;

    /**
     * Provides the "relationships" member of the resource.
     *
     * Returns a map keyed by relationship name; each value is a callable
     * receiving the domain object (plus the active request and relationship
     * name) and returning a to-one or to-many relationship instance.
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship>
     */
    public function getRelationships(mixed $object): array;

    /**
     * @internal
     */
    public function initializeTransformation(JsonApiRequestInterface $request, mixed $object): void;

    /**
     * @internal
     */
    public function clearTransformation(): void;
}
