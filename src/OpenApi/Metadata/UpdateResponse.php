<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Marker for a success response the update operation (`PATCH /{type}/{id}`) may
 * advertise: {@see Ok} (`200`), {@see NoContent} (`204` — no server-side changes) and
 * {@see Accepted} (`202` — accepted for async processing). No other response object
 * implements this interface.
 */
interface UpdateResponse extends OperationResponseInterface {}
