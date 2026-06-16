<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * Raised (400) when a requested `?include` path is recognized as a relationship
 * but is not permitted: either the relation has opted out of inclusion via
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::cannotBeIncluded()}, or
 * the path is outside the root resource's allowed-include-paths whitelist
 * ({@see \haddowg\JsonApi\Serializer\IncludeControlsInterface::getAllowedIncludePaths()}).
 */
final class InclusionNotAllowed extends AbstractJsonApiException
{
    /**
     * @param list<string> $paths
     */
    public function __construct(public readonly array $paths)
    {
        parent::__construct(
            "Included paths '" . \implode(', ', $paths) . "' are not allowed!",
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'INCLUSION_NOT_ALLOWED',
                title: 'Inclusion is not allowed',
                detail: "Included paths '" . \implode(', ', $this->paths) . "' are not allowed by the endpoint!",
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
