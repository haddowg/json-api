<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * One of the standard JSON:API resource-level CRUD operations, as the metadata
 * contract names them. A {@see TypeMetadataInterface} reports which of these are
 * exposed for its type (the per-type operation allow-list); the projector emits a
 * {@see \haddowg\JsonApi\OpenApi\PathItem} only for an allowed operation.
 *
 * The first five are the resource-level operations, the ones an allow-list names;
 * relationship-endpoint and custom-action exposure is described by
 * {@see RelationMetadataInterface} and {@see ActionMetadataInterface} respectively. The
 * rest name the other documents a type declares the top-level `meta` of in
 * {@see \haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface::documentMeta()}: the
 * meta-only `200` of a relationship write, and the job document of a create's or an
 * update's `202`. {@see resourceOperations()} lists the first five.
 */
enum OperationType: string
{
    /** `GET /{type}` — fetch the resource collection. */
    case FetchCollection = 'FetchCollection';

    /** `GET /{type}/{id}` — fetch one resource. */
    case FetchOne = 'FetchOne';

    /** `POST /{type}` — create a resource. */
    case Create = 'Create';

    /** `PATCH /{type}/{id}` — update a resource. */
    case Update = 'Update';

    /** `DELETE /{type}/{id}` — delete a resource. */
    case Delete = 'Delete';

    /** The meta-only `200` of `PATCH /{type}/{id}/relationships/{rel}`. */
    case UpdateRelationship = 'UpdateRelationship';

    /** The meta-only `200` of `POST /{type}/{id}/relationships/{rel}`, to-many only. */
    case AddRelationship = 'AddRelationship';

    /** The meta-only `200` of `DELETE /{type}/{id}/relationships/{rel}`, to-many only. */
    case RemoveRelationship = 'RemoveRelationship';

    /** The job document of a create's `202`. */
    case CreateAccepted = 'CreateAccepted';

    /** The job document of an update's `202`. */
    case UpdateAccepted = 'UpdateAccepted';

    /**
     * The five resource-level operations, which an allow-list names and the projector
     * emits paths for.
     *
     * @return list<self>
     */
    public static function resourceOperations(): array
    {
        return [self::FetchCollection, self::FetchOne, self::Create, self::Update, self::Delete];
    }

    /**
     * Whether this is one of the five {@see resourceOperations()}.
     */
    public function isResourceOperation(): bool
    {
        return \in_array($this, self::resourceOperations(), true);
    }

    /**
     * The `202` job document of this write, a create or an update, or `null` for any
     * other operation.
     */
    public function accepted(): ?self
    {
        return match ($this) {
            self::Create => self::CreateAccepted,
            self::Update => self::UpdateAccepted,
            default => null,
        };
    }
}
