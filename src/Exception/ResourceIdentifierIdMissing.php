<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class ResourceIdentifierIdMissing extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param array<string, mixed> $resourceIdentifier
     */
    public function __construct(public readonly array $resourceIdentifier)
    {
        parent::__construct('An ID or local ID (lid) for the resource identifier must be included!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_IDENTIFIER_ID_MISSING',
            status: 400,
            title: 'An ID for the resource identifier is missing',
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'An ID or local ID (lid) for the resource identifier must be included!',
            ),
        ];
    }
}
