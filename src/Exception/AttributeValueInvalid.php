<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A document attribute carried a value the field's cast could not process — e.g.
 * a string a {@see \haddowg\JsonApi\Resource\Field\DateTime} field refuses because
 * it is not in the shape the field documents or names an impossible date.
 *
 * Rendered as a 422 — the field cast is the last gate before the value is written
 * onto the domain object, so an uncoercible value is an unprocessable entity, not
 * a server fault. The error points at the attribute the client sent
 * (`/data/attributes/<name>`); the underlying reason is carried in `detail`.
 */
final class AttributeValueInvalid extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $attribute,
        public readonly string $reason,
    ) {
        parent::__construct(
            \sprintf('The value for attribute "%s" is invalid: %s', $attribute, $reason),
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATTRIBUTE_VALUE_INVALID',
            status: 422,
            title: 'Attribute value is invalid',
            context: ['attribute' => ErrorContextType::Str, 'reason' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/attributes/publishedAt',
            feature: ErrorFeature::Writes,
            description: 'The server could not accept the value of the attribute at `source.pointer`, for example a date-time it cannot parse. Correct the value and send the request again.',
            exampleDetail: 'The value for attribute "publishedAt" is invalid: "last year" is not an RFC 3339 date-time with an offset, such as 2026-11-05T09:42:00Z',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['attribute' => $this->attribute, 'reason' => $this->reason],
                source: ErrorSource::fromPointer('/data/attributes/' . $this->attribute),
            ),
        ];
    }
}
