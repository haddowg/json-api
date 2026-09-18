<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class InclusionUnrecognized extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param list<string> $unrecognizedInclusions
     */
    public function __construct(public readonly array $unrecognizedInclusions)
    {
        parent::__construct(
            "Included paths '" . \implode(', ', $unrecognizedInclusions) . "' can't be recognized!",
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'INCLUSION_UNRECOGNIZED',
            status: 400,
            title: 'Inclusion is unrecognized',
            context: ['paths' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Included paths '" . \implode(', ', $this->unrecognizedInclusions) . "' can't be recognized by the endpoint!",
                context: ['paths' => \implode(', ', $this->unrecognizedInclusions)],
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
