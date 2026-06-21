<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * One of the standard JSON:API resource-level CRUD operations, as the metadata
 * contract names them. A {@see TypeMetadataInterface} reports which of these are
 * exposed for its type (the per-type operation allow-list); the projector emits a
 * {@see \haddowg\JsonApi\OpenApi\PathItem} only for an allowed operation (Slice 3).
 *
 * These are the resource-level operations only; relationship-endpoint and
 * custom-action exposure is described by {@see RelationMetadataInterface} and
 * {@see ActionMetadataInterface} respectively.
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
}
