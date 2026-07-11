<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **relation builder** for a {@see BelongsTo} to-one relationship
 * backed by a foreign key on the owning model. {@see build()} freezes it into a
 * readonly {@see BelongsTo} value object.
 *
 * Non-final by design: {@see HasOneBuilder} extends it (it builds a {@see HasOne},
 * the inverse-FK to-one, whose only difference is the lazy linkage default).
 */
class BelongsToBuilder extends AbstractRelationBuilder
{
    use DeclaresMonomorphicType;

    /**
     * Eager by default: the foreign key sits on the owning model, so resolving the
     * linkage identifier is free (no query). {@see AbstractRelationBuilder::$dataOnlyWhenLoaded}.
     */
    protected bool $dataOnlyWhenLoaded = false;

    public function build(): BelongsTo
    {
        return new BelongsTo($this->fieldState(), $this->relationState());
    }
}
