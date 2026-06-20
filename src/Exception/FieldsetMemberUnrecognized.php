<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class FieldsetMemberUnrecognized extends AbstractJsonApiException
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
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'FIELDSET_MEMBER_UNRECOGNIZED',
                title: 'Fieldset member is unrecognized',
                detail: "Fields '" . \implode(', ', $this->unrecognizedMembers) . "' requested for type '" . $this->type . "' can't be recognized by the endpoint!",
                source: ErrorSource::fromParameter('fields'),
            ),
        ];
    }
}
