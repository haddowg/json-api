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
            exampleSource: 'accept',
            description: 'Every `application/vnd.api+json` entry in the `Accept` header carries a parameter other than `ext` or `profile`, or asks for an `ext` the server does not support. Accept `application/vnd.api+json` with no other parameters.',
            exampleDetail: 'The media type \'application/vnd.api+json; version=2\' is unacceptable in the \'Accept\' header!',
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
