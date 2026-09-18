<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class MediaTypeUnacceptable extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $mediaTypeName)
    {
        parent::__construct("The media type '$mediaTypeName' is unacceptable in the 'Accept' header!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'MEDIA_TYPE_UNACCEPTABLE',
            status: 406,
            title: 'The provided media type is unacceptable',
            context: ['mediaType' => ErrorContextType::Str, 'header' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['mediaType' => $this->mediaTypeName, 'header' => 'Accept'],
                source: ErrorSource::fromParameter('accept'),
            ),
        ];
    }
}
