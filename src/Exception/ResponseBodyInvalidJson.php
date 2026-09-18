<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class ResponseBodyInvalidJson extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $lintMessage,
        public readonly ?string $originalBody = null,
    ) {
        parent::__construct("Response body is an invalid JSON document: '$lintMessage'!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESPONSE_BODY_INVALID_JSON',
            status: 500,
            title: 'Response body is an invalid JSON document',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                meta: $this->originalBody !== null ? ['original' => $this->originalBody] : [],
            ),
        ];
    }
}
