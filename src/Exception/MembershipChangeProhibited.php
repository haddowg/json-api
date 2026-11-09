<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class MembershipChangeProhibited extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $relationshipName)
    {
        parent::__construct("Changing the members of relationship '$relationshipName' is prohibited!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'MEMBERSHIP_CHANGE_PROHIBITED',
            status: 403,
            title: 'Membership change is prohibited',
            exampleDetail: 'Changing the members of relationship \'tags\' is prohibited!',
            context: ['relationship' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/relationships/tags',
            feature: ErrorFeature::FixedMembership,
            description: 'This relationship keeps the members it has. A replacement may reorder them, but it must list exactly the current members, with none added or left out.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['relationship' => $this->relationshipName],
                source: ErrorSource::fromPointer("/data/relationships/$this->relationshipName"),
            ),
        ];
    }
}
