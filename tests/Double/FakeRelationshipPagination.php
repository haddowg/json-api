<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;

/**
 * A {@see RelationshipPaginationInterface} double returning a fixed
 * {@see RelationshipPagination} for every relation, recording the relations it
 * was asked about so tests can assert the seam was (or was not) consulted. A
 * `null` pagination models "the relation is not paginated for this request".
 */
final class FakeRelationshipPagination implements RelationshipPaginationInterface
{
    /**
     * @var list<RelationInterface>
     */
    public array $askedAbout = [];

    public function __construct(private readonly ?RelationshipPagination $pagination) {}

    public function paginateRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipPagination {
        $this->askedAbout[] = $relation;

        return $this->pagination;
    }
}
