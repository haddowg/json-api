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
 * This is a leaf value object: the readonly property is the accessor — no getters.
 */
final readonly class Target
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $relationship = null,
        public bool $isRelationshipEndpoint = false,
    ) {}

    /**
     * Whether this target addresses a specific resource (carries an `id`).
     */
    public function hasId(): bool
    {
        return $this->id !== null;
    }

    /**
     * Whether this target addresses a relationship (linkage or related).
     */
    public function hasRelationship(): bool
    {
        return $this->relationship !== null;
    }
}
