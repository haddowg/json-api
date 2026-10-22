<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class ResourceIdentifierLidInvalid extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("The resource local ID (lid) must be a string instead of $type!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_IDENTIFIER_LID_INVALID',
            status: 400,
            title: 'Resource identifier local ID is invalid',
            context: ['type' => ErrorContextType::Str],
            feature: ErrorFeature::Writes,
            description: 'A resource identifier in the request carries a `lid` that is not a string. Send every `lid` as a JSON string.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "The resource local ID (lid) must be a string instead of $this->type!",
                context: ['type' => $this->type],
            ),
        ];
    }
}
