<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class ResponseBodyInvalidJson extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $lintMessage,
        public readonly ?string $originalBody = null,
    ) {
        parent::__construct("Response body is an invalid JSON document: '$lintMessage'!", 500);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '500',
                code: 'RESPONSE_BODY_INVALID_JSON',
                title: 'Response body is an invalid JSON document',
                detail: $this->getMessage(),
                meta: $this->originalBody !== null ? ['original' => $this->originalBody] : [],
            ),
        ];
    }
}
