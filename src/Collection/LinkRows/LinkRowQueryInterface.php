<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * Reads one parent's link rows for a {@see MorphToMany} declared with `identifiedBy()`,
 * filtered, sorted and paged per a {@see LinkRowQuery}: what the relation's
 * relationship and related endpoints and its windowed linkage read.
 *
 * A data layer implements it over its own storage: association entities on Doctrine, a
 * morph pivot or row model on Eloquent, applying each sort and filter to the row's
 * column ({@see LinkRowQuery::rowColumn()}) through its own handlers. Each returned
 * {@see IdentifiedMember} carries its row, so core renders its `meta.pivot`, and the
 * host's {@see \haddowg\JsonApi\Serializer\RelationshipLinkageInterface} supplies the
 * page as the relation's linkage. {@see InMemoryLinkRows} is the reference, over the
 * rows the relation's `identifiedBy()` closure returns.
 */
interface LinkRowQueryInterface
{
    /**
     * The page of `$parent`'s link rows `$query` selects, in its order. A paged result
     * carries the total when the query is countable, else whether more rows follow.
     *
     * @return CollectionResult<IdentifiedMember>
     */
    public function queryLinkRows(mixed $parent, MorphToMany $relation, LinkRowQuery $query, JsonApiRequestInterface $request): CollectionResult;
}
