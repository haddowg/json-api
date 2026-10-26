<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * Raised (400) when a `?withCount` query parameter names a relationship that
 * cannot be counted: either the relation is not declared
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelationBuilder::countable()}, or it is
 * a to-one relationship (a count is a to-many cardinality only). `countable()` is
 * the single universal count gate, so a name failing it is rejected here rather
 * than silently ignored — mirroring the include-safeguard rejection of an
 * unpermitted `?include` path.
 */
final class RelationshipCountNotAllowed extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param list<string> $names the offending relationship name(s) named in `?withCount`
     */
    public function __construct(public readonly array $names)
    {
        parent::__construct(
            "Counted relationships '" . \implode(', ', $names) . "' are not allowed!",
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RELATIONSHIP_COUNT_NOT_ALLOWED',
            status: 400,
            title: 'Relationship count is not allowed',
            context: ['names' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            exampleSource: 'withCount',
            feature: ErrorFeature::RelationshipCounts,
            description: '`withCount` names a relationship that is not a countable to-many relationship of this resource. Ask for counts only on the relationships the API marks as countable.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Counted relationships '" . \implode(', ', $this->names) . "' are not countable to-many relationships of this resource!",
                context: ['names' => \implode(', ', $this->names)],
                source: ErrorSource::fromParameter('withCount'),
            ),
        ];
    }
}
