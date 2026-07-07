<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Marker for a success response the delete operation (`DELETE /{type}/{id}`) may
 * advertise: {@see NoContent} (`204`) and {@see MetaResult} (`200` — a meta-only
 * document). Async delete is not expressible through the write seam (`delete()`
 * returns `void`), so there is no `202` carrier. A delete's `200` is {@see MetaResult},
 * never {@see Ok}.
 */
interface DeleteResponse extends OperationResponseInterface {}
