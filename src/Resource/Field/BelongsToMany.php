<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A pivot-backed to-many relationship (`belongsToMany`). Same serialization and
 * constraint surface as {@see HasMany}; pivot-field declarations are
 * **declare-only** in 1.0 (carried as metadata, not validated). The Symfony
 * bundle's Doctrine adapter consumes them.
 */
final class BelongsToMany extends HasMany
{
    /**
     * @var \Closure(): array<string, mixed>|array<string, mixed>
     */
    private \Closure|array $pivotFields = [];

    private ?string $pivotThrough = null;

    /**
     * Declares the pivot (join-table) fields. Declare-only in 1.0.
     *
     * @param \Closure(): array<string, mixed>|array<string, mixed> $fields
     * @return static
     */
    public function fields(\Closure|array $fields): static
    {
        $this->pivotFields = $fields;

        return $this;
    }

    /**
     * The declared pivot fields (resolving a closure form).
     *
     * @return array<string, mixed>
     */
    public function pivotFields(): array
    {
        return $this->pivotFields instanceof \Closure
            ? ($this->pivotFields)()
            : $this->pivotFields;
    }

    /**
     * Names the association entity backing the pivot. Declare-only in 1.0:
     * an opaque class-string the host interprets (the Symfony bundle's Doctrine
     * adapter reads it as the association entity backing the pivot relation,
     * overriding its auto-detection). Core never interprets it. Pass `null` to
     * clear an earlier override.
     *
     * @return static
     */
    public function through(?string $associationEntity): static
    {
        $this->pivotThrough = $associationEntity;

        return $this;
    }

    /**
     * The declared pivot association entity (the `through()` override), or
     * `null` when none was declared.
     */
    public function pivotThrough(): ?string
    {
        return $this->pivotThrough;
    }
}
