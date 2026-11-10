<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A to-many relation that can keep its members in an order the client sets, declared
 * with `positionedBy()`: each member's position is a stored integer, on the link row of
 * a {@see PivotRelationInterface} relation and on the related row of a {@see HasMany}.
 * Every core relation implements it, and one not positioned reports no field.
 *
 * Core declares the field and never writes it. A persister writes positions on every
 * membership change, and {@see Positions} computes them:
 *
 * - a replace sets each member's position to its index in the linkage;
 * - an add appends the new members after the current highest position, in linkage
 *   order, and leaves members already present where they are;
 * - a remove deletes members and leaves gaps, renumbering nothing.
 *
 * The field is read-only to clients, so neither `meta.pivot` nor an attribute sets it,
 * and the relation reads in position order unless it declares a default sort.
 */
interface PositionedRelationInterface extends RelationInterface
{
    /**
     * The name of the field each member's position is stored in, or `null` when the
     * relation is not positioned. It names a pivot field of a
     * {@see PivotRelationInterface} relation and a field of the related type otherwise.
     */
    public function positionField(): ?string;
}
