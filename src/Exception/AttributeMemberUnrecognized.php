<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A write body sent a member the resource type does not declare: an attribute no field
 * names, or a key inside an `Obj`, `Map`, `OneOf` variant or structured list element
 * that none of its children name. A declared shape is closed, so the member is refused
 * rather than silently dropped.
 *
 * Carries one error per offending member, each pointing at it.
 */
final class AttributeMemberUnrecognized extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param non-empty-list<string> $pointers the JSON Pointer of each undeclared member
     */
    public function __construct(public readonly array $pointers)
    {
        parent::__construct(
            \sprintf("The resource does not declare '%s'!", \implode("', '", $pointers)),
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATTRIBUTE_MEMBER_UNRECOGNIZED',
            status: 400,
            title: 'Attribute member is not recognized',
            context: ['member' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/attributes/colour',
            feature: ErrorFeature::Writes,
            description: 'The request body sent an attribute, or a key inside an object attribute, that the resource type does not declare. Remove it, or correct its name, and send the request again.',
            exampleDetail: "The resource does not declare the member 'colour'!",
        );
    }

    public function getErrors(): array
    {
        $errors = [];
        foreach ($this->pointers as $pointer) {
            $segments = \explode('/', $pointer);
            $member = \str_replace(['~1', '~0'], ['/', '~'], \end($segments));
            $errors[] = self::describe()->toError(
                detail: "The resource does not declare the member '$member'!",
                context: ['member' => $member],
                source: ErrorSource::fromPointer($pointer),
            );
        }

        return $errors;
    }
}
