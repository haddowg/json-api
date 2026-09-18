<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * Every error code core can raise, as data.
 *
 * The exceptions are the source of truth — each one's {@see ErrorDescriptor} is the
 * same object its `getErrors()` renders through — but a class is not a list, so the
 * membership is spelled out here. An explicit roster is the honest form: it is
 * greppable, it diffs, and it never has to construct an exception with invented
 * arguments to find out what code it carries. `ErrorCatalogTest` fails when a new
 * exception lands without joining it.
 *
 * The list is core's, not the world's: an application throws codes this catalogue has
 * never heard of, which is why the projected `anyOf` keeps an open generic `Error`
 * branch alongside the named variants
 * ([ADR 0136](../../docs/adr/0136-open-error-code-catalogue-in-the-projected-document.md)).
 */
final class ErrorCatalog
{
    /**
     * Core's described exceptions, in class-name order — which fixes the order the
     * OpenAPI projection emits their components in.
     *
     * @return list<class-string<DescribedErrorInterface>>
     */
    public static function exceptions(): array
    {
        return [
            AdditionProhibited::class,
            ApplicationError::class,
            AtomicOperationsInvalid::class,
            AttributeValueInvalid::class,
            ClientGeneratedIdAlreadyExists::class,
            ClientGeneratedIdNotSupported::class,
            ClientGeneratedIdRequired::class,
            CursorMalformed::class,
            CursorStale::class,
            DataMemberMissing::class,
            FieldsetMemberUnrecognized::class,
            FilterParamUnrecognized::class,
            FilterValueInvalid::class,
            FullReplacementProhibited::class,
            InclusionDepthExceeded::class,
            InclusionNotAllowed::class,
            InclusionUnrecognized::class,
            InclusionUnsupported::class,
            LocalIdConflict::class,
            LocalIdNotFound::class,
            LocalIdNotSupported::class,
            MediaTypeUnacceptable::class,
            MediaTypeUnsupported::class,
            NoResourceRegistered::class,
            PaginationKindUnknown::class,
            QueryParamMalformed::class,
            QueryParamUnrecognized::class,
            RelatedAttributeOwnerMissing::class,
            RelationshipCountNotAllowed::class,
            RelationshipNotExists::class,
            RelationshipTypeInappropriate::class,
            RemovalProhibited::class,
            RequestBodyInvalidJson::class,
            RequestBodyInvalidJsonApi::class,
            RequiredTopLevelMembersMissing::class,
            ResourceIdConflict::class,
            ResourceIdentifierIdInvalid::class,
            ResourceIdentifierIdMissing::class,
            ResourceIdentifierLidInvalid::class,
            ResourceIdentifierTypeInvalid::class,
            ResourceIdentifierTypeMissing::class,
            ResourceIdInvalid::class,
            ResourceIdMissing::class,
            ResourceIdUndecodable::class,
            ResourceNotFound::class,
            ResourceTypeMissing::class,
            ResourceTypeUnacceptable::class,
            ResponseBodyInvalidJson::class,
            ResponseBodyInvalidJsonApi::class,
            SortingUnsupported::class,
            SortParamUnrecognized::class,
            TopLevelMemberNotAllowed::class,
            TopLevelMembersIncompatible::class,
        ];
    }

    /**
     * The descriptors themselves, in the same order.
     *
     * @return list<ErrorDescriptor>
     */
    public static function descriptors(): array
    {
        return \array_map(
            static fn(string $exception): ErrorDescriptor => $exception::describe(),
            self::exceptions(),
        );
    }
}
