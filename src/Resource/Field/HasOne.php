<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A to-one relationship backed by a foreign key on the **related** model
 * (`hasOne`). Identical metadata to {@see BelongsTo}; the distinction is for
 * data-layer adapters.
 */
final class HasOne extends BelongsTo {}
