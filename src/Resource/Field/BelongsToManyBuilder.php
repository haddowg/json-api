<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **relation builder** for a pivot-backed {@see BelongsToMany} to-many
 * relationship. Adds the **pivot-field declarations** ({@see fields()}) and the
 * association-entity override ({@see through()}) on top of the {@see HasManyBuilder}
 * surface; {@see build()} freezes it — with its built pivot fields — into a readonly
 * {@see BelongsToMany} value object.
 */
final class BelongsToManyBuilder extends HasManyBuilder
{
    use DeclaresPivotFields;

    public function build(): BelongsToMany
    {
        return new BelongsToMany($this->fieldState(), $this->relationState(), $this->pivotFields, $this->pivotThrough);
    }
}
