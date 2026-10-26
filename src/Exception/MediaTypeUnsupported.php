<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class MediaTypeUnsupported extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $mediaTypeName)
    {
        parent::__construct("The media type '$mediaTypeName' is unsupported in the 'Content-Type' header!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'MEDIA_TYPE_UNSUPPORTED',
            status: 415,
            title: 'The provided media type is unsupported',
            context: ['mediaType' => ErrorContextType::Str, 'header' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            description: 'The `Content-Type` is missing or names a media type the endpoint does not accept, or is `application/vnd.api+json` with a media type parameter other than `ext` or `profile`, or with an `ext` the server does not support. Send a JSON:API document as `Content-Type: application/vnd.api+json` with only the parameters the server supports.',
            exampleDetail: 'The media type \'application/vnd.api+json; charset=utf-8\' is unsupported in the \'Content-Type\' header!',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['mediaType' => $this->mediaTypeName, 'header' => 'Content-Type'],
                source: ErrorSource::fromParameter('content-type'),
            ),
        ];
    }
}
