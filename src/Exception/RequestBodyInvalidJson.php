<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class RequestBodyInvalidJson extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $lintMessage,
        public readonly ?string $originalBody = null,
    ) {
        parent::__construct("Request body is an invalid JSON document: '$lintMessage'!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'REQUEST_BODY_INVALID_JSON',
                title: 'Request body is an invalid JSON document',
                detail: $this->getMessage(),
                meta: $this->originalBody !== null ? ['original' => $this->originalBody] : [],
            ),
        ];
    }
}
