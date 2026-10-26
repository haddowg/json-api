<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A write body sent an attribute the client may not write in this request: one that is
 * read-only, computed, or read-only on this verb or by its request predicate. Raised
 * only on a server that opted in with
 * {@see \haddowg\JsonApi\Server\Server::withReadOnlyAttributesRejected()}; otherwise such
 * an attribute is ignored, which the spec equally allows.
 *
 * Carries one error per offending attribute, each pointing at its member.
 */
final class AttributeWriteProhibited extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param non-empty-list<string> $attributes
     */
    public function __construct(public readonly array $attributes)
    {
        parent::__construct(
            \sprintf("Attribute '%s' cannot be written in this request!", \implode("', '", $attributes)),
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATTRIBUTE_WRITE_PROHIBITED',
            status: 403,
            title: 'Attribute write is prohibited',
            context: ['attribute' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/attributes/createdAt',
            feature: ErrorFeature::ReadOnlyAttributeRejection,
            description: 'The request body sent an attribute the client cannot write in this request, such as a read-only or computed one. Remove the member from `data.attributes` and send the request again.',
            exampleDetail: "Attribute 'createdAt' cannot be written in this request!",
        );
    }

    public function getErrors(): array
    {
        $errors = [];
        foreach ($this->attributes as $attribute) {
            $errors[] = self::describe()->toError(
                detail: "Attribute '$attribute' cannot be written in this request!",
                context: ['attribute' => $attribute],
                source: ErrorSource::fromPointer("/data/attributes/$attribute"),
            );
        }

        return $errors;
    }
}
