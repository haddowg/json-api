<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * How a custom action ({@see ActionMetadataInterface}) answers on success — the
 * discriminator the projector uses to build the action's success response:
 *
 *  - {@see Document} — a JSON:API document whose `data` is the action's
 *    {@see ActionMetadataInterface::outputType()} resource (a `200` with that
 *    type's document schema).
 *  - {@see Meta}     — a JSON:API document whose primary content is its top-level
 *    `meta` (a `200` with the shared meta-document schema; no `data`).
 *  - {@see None}     — no response body (a `204 No Content`).
 */
enum ActionOutputMode: string
{
    case Document = 'document';

    case Meta = 'meta';

    case None = 'none';
}
