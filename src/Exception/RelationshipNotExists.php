<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class RelationshipNotExists extends AbstractJsonApiException
{
    public function __construct(public readonly string $relationship)
    {
        parent::__construct("The requested relationship '$relationship' does not exist!", 404);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '404',
                code: 'RELATIONSHIP_NOT_EXISTS',
                title: 'The requested relationship does not exist!',
                detail: $this->getMessage(),
            ),
        ];
    }
}
