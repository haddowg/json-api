<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * Raised (400) when a `?withCount` query parameter names a relationship that
 * cannot be counted: either the relation is not declared
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::countable()}, or it is
 * a to-one relationship (a count is a to-many cardinality only). `countable()` is
 * the single universal count gate, so a name failing it is rejected here rather
 * than silently ignored — mirroring the include-safeguard rejection of an
 * unpermitted `?include` path.
 */
final class RelationshipCountNotAllowed extends AbstractJsonApiException
{
    /**
     * @param list<string> $names the offending relationship name(s) named in `?withCount`
     */
    public function __construct(public readonly array $names)
    {
        parent::__construct(
            "Counted relationships '" . \implode(', ', $names) . "' are not allowed!",
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'RELATIONSHIP_COUNT_NOT_ALLOWED',
                title: 'Relationship count is not allowed',
                detail: "Counted relationships '" . \implode(', ', $this->names) . "' are not countable to-many relationships of this resource!",
                source: ErrorSource::fromParameter('withCount'),
            ),
        ];
    }
}
