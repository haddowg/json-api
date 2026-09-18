<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * Raised (400) when a requested `?include` path is recognized as a relationship
 * but is not permitted: either the relation has opted out of inclusion via
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelationBuilder::cannotBeIncluded()}, or
 * the path is outside the root resource's allowed-include-paths whitelist
 * ({@see \haddowg\JsonApi\Serializer\IncludeControlsInterface::getAllowedIncludePaths()}).
 */
final class InclusionNotAllowed extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param list<string> $paths
     */
    public function __construct(public readonly array $paths)
    {
        parent::__construct(
            "Included paths '" . \implode(', ', $paths) . "' are not allowed!",
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'INCLUSION_NOT_ALLOWED',
            status: 400,
            title: 'Inclusion is not allowed',
            context: ['paths' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Included paths '" . \implode(', ', $this->paths) . "' are not allowed by the endpoint!",
                context: ['paths' => \implode(', ', $this->paths)],
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
