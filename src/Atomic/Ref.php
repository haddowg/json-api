<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

/**
 * The `ref` member of an atomic operation: a structural reference to the resource
 * (or relationship) the operation targets, as an alternative to a `href`.
 *
 * A ref always names a `type` and identifies the resource by exactly one of `id`
 * or `lid` (the latter referencing a resource created earlier in the same batch).
 * An optional `relationship` narrows the target to that relationship of the named
 * resource — turning a resource operation into a relationship operation.
 *
 * This is a leaf value object: the readonly property is the accessor — no getters.
 * Structural validation (type required, id XOR lid) is the parser's job; this VO
 * only holds the parsed members.
 *
 * @see https://jsonapi.org/ext/atomic/#operation-objects
 */
final readonly class Ref
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $lid = null,
        public ?string $relationship = null,
    ) {}

    /**
     * Whether this ref identifies its resource by a local id (vs a server id).
     */
    public function hasLid(): bool
    {
        return $this->lid !== null;
    }

    /**
     * Whether this ref narrows the target to a relationship of the named resource.
     */
    public function hasRelationship(): bool
    {
        return $this->relationship !== null;
    }
}
