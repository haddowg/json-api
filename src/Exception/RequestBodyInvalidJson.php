<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Resource\Field\Str;

final class RequestBodyInvalidJson extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $lintMessage,
        public readonly ?string $originalBody = null,
    ) {
        parent::__construct("Request body is an invalid JSON document: '$lintMessage'!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'REQUEST_BODY_INVALID_JSON',
            status: 400,
            title: 'Request body is an invalid JSON document',
            exampleDetail: 'Request body is an invalid JSON document: \'Syntax error\'!',
            context: ['message' => ErrorContextType::Str],
            feature: ErrorFeature::Writes,
            description: 'The request body is not well-formed JSON. Fix the syntax the `detail` reports and send the request again.',
            meta: [
                Str::make('original')->describedAs('The raw request body that failed to parse, when the server has it.'),
            ],
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['message' => $this->lintMessage],
                meta: $this->originalBody !== null ? ['original' => $this->originalBody] : [],
            ),
        ];
    }
}
