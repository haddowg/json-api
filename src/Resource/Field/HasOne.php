<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A to-one relationship backed by a foreign key on the **related** model
 * (`hasOne`). Identical metadata to {@see BelongsTo}; the distinction is for
 * data-layer adapters.
 */
final class HasOne extends BelongsTo
{
    /**
     * Lazy by default (overriding {@see BelongsTo}'s eager default): the foreign key
     * sits on the *related* model, so resolving the linkage identifier is a query —
     * the same N+1 risk as a to-many. {@see AbstractRelation::$dataOnlyWhenLoaded}.
     */
    protected bool $dataOnlyWhenLoaded = true;
}
