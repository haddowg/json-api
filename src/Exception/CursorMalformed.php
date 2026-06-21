<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A `page[after]` / `page[before]` cursor token could not be decoded — it is not
 * valid base64url, not valid JSON, or does not decode to the expected boundary
 * shape. Surfaced as a `400` with `source.parameter` naming the offending
 * `page[…]` cursor parameter, distinct from a {@see CursorStale} (a well-formed
 * token whose columns no longer match the active sort).
 */
final class CursorMalformed extends AbstractJsonApiException
{
    /**
     * @param string $parameter the cursor parameter that was malformed, e.g. `page[after]` or `page[before]`
     */
    public function __construct(public readonly string $parameter)
    {
        parent::__construct("Cursor parameter '$parameter' is malformed!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'CURSOR_MALFORMED',
                title: 'Cursor is malformed',
                detail: "The cursor supplied in '$this->parameter' could not be decoded.",
                source: ErrorSource::fromParameter($this->parameter),
            ),
        ];
    }
}
