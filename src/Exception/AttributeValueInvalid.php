<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A document attribute carried a value the field's cast could not process — e.g.
 * a calendar-garbage or otherwise unparseable string reaching a
 * {@see \haddowg\JsonApi\Resource\Field\DateTime} field's
 * `new \DateTimeImmutable($value)` coercion.
 *
 * Rendered as a 422 — the field cast is the last gate before the value is written
 * onto the domain object, so an uncoercible value is an unprocessable entity, not
 * a server fault. The error points at the attribute the client sent
 * (`/data/attributes/<name>`); the underlying reason is carried in `detail`.
 */
final class AttributeValueInvalid extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $attribute,
        public readonly string $reason,
    ) {
        parent::__construct(
            \sprintf('The value for attribute "%s" is invalid: %s', $attribute, $reason),
            422,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '422',
                code: 'ATTRIBUTE_VALUE_INVALID',
                title: 'Attribute value is invalid',
                detail: $this->getMessage(),
                context: ['attribute' => $this->attribute, 'reason' => $this->reason],
                source: ErrorSource::fromPointer('/data/attributes/' . $this->attribute),
            ),
        ];
    }
}
