<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class RequiredTopLevelMembersMissing extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct(
            'A document must contain at least one of the following top-level members: "data", "errors", "meta"',
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'REQUIRED_TOP_LEVEL_MEMBERS_MISSING',
            status: 400,
            title: 'Required top-level members are missing',
            exampleDetail: 'A document must contain at least one of the following top-level members: "data", "errors", "meta"',
            feature: ErrorFeature::Writes,
            description: 'The request body is not a JSON object, or has none of the top-level members `data`, `errors` and `meta`. Send a JSON object with the resource under `data`.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'A document must contain at least one of the following top-level members: "data", "errors", "meta"',
            ),
        ];
    }
}
