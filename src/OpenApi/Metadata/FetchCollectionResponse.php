<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Marker for a success response the fetch-collection operation (`GET /{type}`) may
 * advertise: {@see Ok} (`200`). No other response object implements this interface;
 * it exists to keep the per-operation response model uniform.
 */
interface FetchCollectionResponse extends OperationResponseInterface {}
