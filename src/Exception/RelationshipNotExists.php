<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class RelationshipNotExists extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $relationship)
    {
        parent::__construct("The requested relationship '$relationship' does not exist!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RELATIONSHIP_NOT_EXISTS',
            status: 404,
            title: 'The requested relationship does not exist!',
            context: ['relationship' => ErrorContextType::Str],
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['relationship' => $this->relationship],
            ),
        ];
    }
}
