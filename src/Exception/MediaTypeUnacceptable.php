<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class MediaTypeUnacceptable extends AbstractJsonApiException
{
    public function __construct(public readonly string $mediaTypeName)
    {
        parent::__construct("The media type '$mediaTypeName' is unacceptable in the 'Accept' header!", 406);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '406',
                code: 'MEDIA_TYPE_UNACCEPTABLE',
                title: 'The provided media type is unacceptable',
                detail: $this->getMessage(),
                source: ErrorSource::fromParameter('accept'),
            ),
        ];
    }
}
