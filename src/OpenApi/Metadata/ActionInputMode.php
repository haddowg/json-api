<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * How a custom action ({@see ActionMetadataInterface}) consumes its request body —
 * the discriminator the projector uses to build the action's `requestBody` (Slice 3):
 *
 *  - {@see None}     — no request body.
 *  - {@see Document} — a JSON:API document whose `data` is the action's `inputType`
 *    resource (the request schema of that type).
 *  - {@see Raw}      — an opaque/author-defined body (a generic media type with a
 *    permissive schema), with relaxed content-type negotiation.
 */
enum ActionInputMode: string
{
    case None = 'none';

    case Document = 'document';

    case Raw = 'raw';
}
