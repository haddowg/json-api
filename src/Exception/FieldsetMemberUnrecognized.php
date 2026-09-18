<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class FieldsetMemberUnrecognized extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string       $type                 the resource type whose `fields[type]` carried the unknown member(s)
     * @param list<string> $unrecognizedMembers the requested member names that name no declared field of `$type`
     */
    public function __construct(
        public readonly string $type,
        public readonly array $unrecognizedMembers,
    ) {
        parent::__construct(
            "Fields '" . \implode(', ', $unrecognizedMembers) . "' requested for type '" . $type . "' can't be recognized!",
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'FIELDSET_MEMBER_UNRECOGNIZED',
            status: 400,
            title: 'Fieldset member is unrecognized',
            context: ['members' => ErrorContextType::Str, 'type' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Fields '" . \implode(', ', $this->unrecognizedMembers) . "' requested for type '" . $this->type . "' can't be recognized by the endpoint!",
                context: ['members' => \implode(', ', $this->unrecognizedMembers), 'type' => $this->type],
                source: ErrorSource::fromParameter('fields'),
            ),
        ];
    }
}
