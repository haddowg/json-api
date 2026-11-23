<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Resource\DateMessage;
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
 *
 * A value that is not the date it should be carries its {@see DateMessage}: its `detail`
 * is that message alone, its context fills an `{example}` placeholder, and a resolver may
 * translate it under `ATTRIBUTE_VALUE_INVALID.date_time`, `.date`, `.time` or
 * `.formatted_date_time`. {@see at()} moves the error under a parent and keeps the message.
 */
final class AttributeValueInvalid extends AbstractJsonApiException implements DescribedErrorInterface
{
    public readonly string $reason;

    /**
     * @param string       $attribute the pointer below `/data/attributes`, `schedule/startsAt` for a nested member
     * @param string       $reason    why the value is refused, ignored when `$date` is given
     * @param ?DateMessage $date      the message refusing a value that is not a date
     */
    public function __construct(
        public readonly string $attribute,
        string $reason,
        public readonly ?DateMessage $date = null,
    ) {
        $this->reason = $date?->message() ?? $reason;

        parent::__construct(
            $date !== null ? $this->reason : \sprintf('The value for attribute "%s" is invalid: %s', $attribute, $reason),
            self::describe()->status,
        );
    }

    /**
     * The value at `$attribute` is not the date its field takes.
     */
    public static function forDate(string $attribute, DateMessage $date): self
    {
        return new self($attribute, '', $date);
    }

    /**
     * The same error at another pointer, as a composite field moves a member's error under
     * its own name.
     */
    public function at(string $attribute): self
    {
        return new self($attribute, $this->reason, $this->date);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATTRIBUTE_VALUE_INVALID',
            status: 422,
            title: 'Attribute value is invalid',
            context: ['attribute' => ErrorContextType::Str, 'reason' => ErrorContextType::Str, 'example' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/attributes/publishedAt',
            feature: ErrorFeature::Writes,
            description: 'The server could not accept the value of the attribute at `source.pointer`, for example a date-time it cannot parse. Correct the value and send the request again.',
            exampleDetail: 'This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['attribute' => $this->attribute, 'reason' => $this->reason, ...$this->date?->context() ?? []],
                source: ErrorSource::fromPointer('/data/attributes/' . $this->attribute),
                detailVariant: $this->date?->detailVariant(self::describe()->code) ?? '',
            ),
        ];
    }
}
