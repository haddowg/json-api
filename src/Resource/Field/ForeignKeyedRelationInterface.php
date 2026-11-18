<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A relation that can read its members through a foreign key on the related type,
 * declared as the third argument to `HasMany::make()` or `HasOne::make()`. Every core
 * relation implements it, and one with no foreign key reports `null`.
 *
 * A separate interface rather than a method on {@see RelationInterface}, so a host's own
 * relation implementation keeps compiling. A data layer resolves the name against the
 * related type through {@see ForeignKey::of()}.
 */
interface ForeignKeyedRelationInterface extends RelationInterface
{
    /**
     * The name of the related type's field or to-one relation that holds the parent's
     * id, or `null` when the relation reads the members its parent model holds.
     */
    public function foreignKey(): ?string;
}
