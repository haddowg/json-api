<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class TopLevelMemberNotAllowed extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct(
            'If a document does not contain a top-level "data" key, the "included" member must not be present either.',
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'TOP_LEVEL_MEMBER_NOT_ALLOWED',
            status: 400,
            title: 'Top-level member is not allowed',
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'If a document does not contain a top-level "data" key, the "included" member must not be present either.',
            ),
        ];
    }
}
