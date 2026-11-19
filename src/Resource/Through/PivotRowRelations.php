<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * What a pivot row links to beyond the relation's two ends. A pivot row has no type, so
 * core can't read the relations it holds from any declaration; the data layer reads them
 * from its storage, such as the associations of a Doctrine association entity, and names
 * the type each one reaches.
 *
 * A `->through()` path that continues past a `pivot` segment, such as `pivot.addedBy`,
 * walks the relation this returns, and the reached type's fields, scope and read rules
 * apply from there as they do on any hop.
 */
interface PivotRowRelations
{
    /**
     * The relation the pivot rows of `$relation`, declared on `$parentType`, hold under
     * the member `$member`, or `null` when they hold none. It must relate to one registered
     * type. Return the same instance for the same arguments, since a data layer matches
     * the hops a gate refused by identity.
     *
     * @throws \LogicException when the member is a relation the data layer can't name one type for
     */
    public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface;
}
