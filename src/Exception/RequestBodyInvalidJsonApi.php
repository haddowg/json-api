<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class RequestBodyInvalidJsonApi extends AbstractJsonApiException
{
    /**
     * @param list<array{message: string, property?: string}> $validationErrors
     */
    public function __construct(
        public readonly array $validationErrors,
        public readonly mixed $originalBody = null,
        public readonly bool $includeOriginalBody = false,
    ) {
        parent::__construct('Request body is an invalid JSON:API document!' . \print_r($validationErrors, true), 400);
    }

    public function getErrors(): array
    {
        $errors = [];
        $first = true;

        foreach ($this->validationErrors as $validationError) {
            $property = $validationError['property'] ?? '';

            $errors[] = new Error(
                status: '400',
                code: 'REQUEST_BODY_INVALID_JSON_API',
                title: 'Request body is an invalid JSON:API document',
                detail: \ucfirst($validationError['message']),
                source: $property !== '' ? ErrorSource::fromPointer($property) : null,
                meta: $first && $this->includeOriginalBody ? ['original' => $this->originalBody] : [],
            );

            $first = false;
        }

        return $errors;
    }
}
