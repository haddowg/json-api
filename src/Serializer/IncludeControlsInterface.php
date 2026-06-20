<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * An opt-in capability a {@see SerializerInterface} MAY implement to constrain
 * how its resources participate in compound documents. The transformer and
 * documents read it via `instanceof IncludeControlsInterface` (it is NOT part of
 * {@see SerializerInterface}, so a serializer that does not implement it is
 * fully unrestricted — every declared relationship includable, no depth cap, no
 * path whitelist, exactly today's behaviour).
 *
 * It carries the three include safeguards:
 *  - per-relation opt-out ({@see getNonIncludableRelationships()}),
 *  - a per-resource maximum include depth override ({@see maxIncludeDepth()}),
 *  - a root-scoped allowed-include-paths whitelist ({@see getAllowedIncludePaths()}).
 *
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements this with concrete
 * defaults, so every Resource subclass satisfies the interface automatically.
 */
interface IncludeControlsInterface
{
    /**
     * The relationship names that are NOT includable for this resource — a
     * `?include` naming any of them (at any path) is rejected with
     * {@see \haddowg\JsonApi\Exception\InclusionNotAllowed} (400), and they are
     * excluded from the default-include cascade. Evaluated per-resource-level
     * during the transformer's recursion (a relation's own includability), so it
     * receives the `$request` and the domain `$object` — a relation may declare
     * its includability as a request predicate
     * ({@see \haddowg\JsonApi\Resource\Field\AbstractRelation::cannotBeIncluded()}
     * with a closure). Return an empty list to keep every relationship includable.
     *
     * @return list<string>
     */
    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array;

    /**
     * This resource's maximum include depth override (number of relationship hops
     * from the primary resource), or `null` to defer to the server default.
     * Resolved only when this resource is the request's primary/root type; a value
     * `<= 0` is treated as unlimited.
     */
    public function maxIncludeDepth(): ?int;

    /**
     * The full dotted include paths a client may request when THIS resource is the
     * request's primary/root type (e.g. `['posts', 'posts.author']`). Evaluated
     * ONCE against the root resource and governs the whole nested include tree, so
     * it can forbid a relation as a nested path even where that relation is
     * includable from its own resource. `null` is unrestricted (today's
     * behaviour); an empty list `[]` permits no includes at all.
     *
     * @return list<string>|null
     */
    public function getAllowedIncludePaths(): ?array;
}
