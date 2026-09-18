<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class TopLevelMembersIncompatible extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The members "data" and "errors" cannot coexist in the same document', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'TOP_LEVEL_MEMBERS_INCOMPATIBLE',
            status: 400,
            title: 'Top-level members are incompatible',
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'The members "data" and "errors" cannot coexist in the same document',
            ),
        ];
    }
}
