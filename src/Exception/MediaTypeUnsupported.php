<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class MediaTypeUnsupported extends AbstractJsonApiException
{
    public function __construct(public readonly string $mediaTypeName)
    {
        parent::__construct("The media type '$mediaTypeName' is unsupported in the 'Content-Type' header!", 415);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '415',
                code: 'MEDIA_TYPE_UNSUPPORTED',
                title: 'The provided media type is unsupported',
                detail: $this->getMessage(),
                source: ErrorSource::fromParameter('content-type'),
            ),
        ];
    }
}
