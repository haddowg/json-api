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
}
