<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class InclusionUnrecognized extends AbstractJsonApiException
{
    /**
     * @param list<string> $unrecognizedInclusions
     */
    public function __construct(public readonly array $unrecognizedInclusions)
    {
        parent::__construct(
            "Included paths '" . \implode(', ', $unrecognizedInclusions) . "' can't be recognized!",
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'INCLUSION_UNRECOGNIZED',
                title: 'Inclusion is unrecognized',
                detail: "Included paths '" . \implode(', ', $this->unrecognizedInclusions) . "' can't be recognized by the endpoint!",
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
