<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

/**
 * Identifies the JSON:API endpoint an operation acts on, independent of PSR-7.
 *
 * A target names the primary resource `type`, optionally a specific resource
 * `id`, and optionally a `relationship` name. When a relationship is present,
 * {@see $isRelationshipEndpoint} distinguishes the relationship-linkage endpoint
 * (`/articles/1/relationships/author`, `true`) from the related-resource endpoint
 * (`/articles/1/author`, `false`).
 *
 * A **singleton** target ({@see $singleton}) addresses a singleton type's one resource for
 * the caller, routed at `/{uriType}` with no id segment. Its {@see $id} is the id the type
 * resolved for the caller, or `null` until it is resolved:
 * {@see OperationFactory::fromRequest()} resolves a singleton target that has none, and
 * answers `404` when the type names none.
 *
 * This is a leaf value object: the readonly property is the accessor — no getters.
 */
final readonly class Target
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $relationship = null,
        public bool $isRelationshipEndpoint = false,
        public bool $singleton = false,
    ) {}

    /**
     * A target on the singleton type `$type`: its resource, a relationship or related
     * endpoint, or a custom action, at `/{uriType}`. `$id` is the caller's resolved id, or
     * `null` to have {@see OperationFactory} resolve it.
     */
    public static function singleton(
        string $type,
        ?string $id = null,
        ?string $relationship = null,
        bool $isRelationshipEndpoint = false,
    ): self {
        return new self($type, $id, $relationship, $isRelationshipEndpoint, true);
    }

    /**
     * A copy carrying the resolved id `$id`.
     */
    public function withId(?string $id): self
    {
        return new self($this->type, $id, $this->relationship, $this->isRelationshipEndpoint, $this->singleton);
    }

    /**
     * Whether this target addresses a specific resource (carries an `id`).
     */
    public function hasId(): bool
    {
        return $this->id !== null;
    }

    /**
     * Whether this target addresses one resource rather than a collection: it carries an
     * `id`, or it is a singleton target, which always addresses the caller's resource.
     */
    public function addressesResource(): bool
    {
        return $this->id !== null || $this->singleton;
    }

    /**
     * Whether this target addresses a relationship (linkage or related).
     */
    public function hasRelationship(): bool
    {
        return $this->relationship !== null;
    }
}
