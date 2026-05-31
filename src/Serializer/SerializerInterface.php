<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * Maps a domain value to a JSON:API resource: its `type`, `id`, `meta`, `links`,
 * attributes and relationships. The recommended way to implement this is the
 * fluent {@see \haddowg\JsonApi\Resource\AbstractResource}; implement it directly
 * (or extend {@see AbstractSerializer}) only when you need full control of how a
 * domain object becomes a resource.
 *
 * Every method is a pure function of its arguments — the serializer holds no
 * per-pass state, so a single instance safely serializes many objects (including
 * recursively included ones). A resource's identity (`type`/`id`) and its default
 * includes depend only on the object; the request-shaped members (`meta`,
 * `links`, attributes, relationships) receive the request directly.
 *
 * `getAttributes()` / `getRelationships()` return **maps of callables** so the
 * engine can invoke only the members that survive sparse-fieldset filtering, each
 * callable receiving the domain object, the request and the member name.
 *
 * @see https://www.jsonapi.org/ — JSON:API specification
 */
interface SerializerInterface
{
    /**
     * The resource `type` member for the given domain object.
     */
    public function getType(mixed $object): string;

    /**
     * The resource `id` member for the given domain object. A resource's identity
     * must not vary by request, so this receives only the object.
     */
    public function getId(mixed $object): string;

    /**
     * The resource `meta` member, or an empty array to omit it.
     *
     * @return array<string, mixed>
     */
    public function getMeta(mixed $object, JsonApiRequestInterface $request): array;

    /**
     * The resource-level `links` member, or null to omit it.
     */
    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks;

    /**
     * The attribute callables keyed by member name. Each callable is invoked with
     * the domain object, the request and the member name only if the attribute
     * survives sparse-fieldset filtering. The request is also passed to this
     * method so the set of attributes may itself be request-dependent.
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed>
     */
    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array;

    /**
     * The default relationship names to include when the request does not specify
     * an `include` query parameter.
     *
     * @return list<string>
     */
    public function getDefaultIncludedRelationships(mixed $object): array;

    /**
     * The relationship callables keyed by member name. The request is also passed
     * to this method so the set of relationships may itself be request-dependent.
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship>
     */
    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array;
}
