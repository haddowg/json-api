<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * Reads one parent's link rows for an identified {@see MorphToMany}, filtered, sorted and
 * paged per a {@see LinkRowQuery}: what the relation's relationship and related
 * endpoints and its windowed linkage read.
 *
 * A data layer implements it over its own storage for a relation declared with
 * `identifiedByRows()`, reading everything it needs off the query and its
 * {@see LinkRowQuery::$fields}: select rows of the `through` class whose owner field is
 * the parent and whose type field holds one of {@see LinkRowQuery::typeValues()} or,
 * with its id, matches {@see LinkRowQuery::admittedIds()}; apply each filter and sort
 * to the row's column ({@see LinkRowQuery::rowColumn()}) through its own handlers;
 * order ties by {@see LinkRowQuery::tiebreak()}, ascending; then page. Turn each row
 * into its member with {@see LinkRowQuery::member()}. Each returned
 * {@see IdentifiedMember} carries its row, so core renders its `meta.pivot`, and the
 * host's {@see \haddowg\JsonApi\Serializer\RelationshipLinkageInterface} supplies the
 * page as the relation's linkage.
 *
 * Narrow the query first with {@see LinkRowQuery::narrowed()}, over the data layer's
 * {@see LinkRowKeysInterface}, when a member type's rows may be hidden by a scope.
 *
 * A relation declared with `identifiedBy()` has no declared fields, and is served by
 * {@see InMemoryLinkRows}, the reference every implementation must agree with.
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
