<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class RequestBodyInvalidJsonApi extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param list<array{message: string, property?: string}> $validationErrors
     */
    public function __construct(
        public readonly array $validationErrors,
        public readonly mixed $originalBody = null,
        public readonly bool $includeOriginalBody = false,
    ) {
        parent::__construct('Request body is an invalid JSON:API document!' . \print_r($validationErrors, true), self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'REQUEST_BODY_INVALID_JSON_API',
            status: 400,
            title: 'Request body is an invalid JSON:API document',
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        $errors = [];
        $first = true;

        foreach ($this->validationErrors as $validationError) {
            $property = $validationError['property'] ?? '';

            $errors[] = self::describe()->toError(
                detail: \ucfirst($validationError['message']),
                source: $property !== '' ? ErrorSource::fromPointer($property) : null,
                meta: $first && $this->includeOriginalBody ? ['original' => $this->originalBody] : [],
            );

            $first = false;
        }

        return $errors;
    }
}
