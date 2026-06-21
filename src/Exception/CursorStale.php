<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A well-formed cursor token whose encoded keyset columns no longer match the
 * request's active sort — the client changed `?sort` while paging, so the cursor
 * cannot be honoured against the new ordering.
 *
 * Defined in core for the typed `400` contract; it is **thrown by the executing
 * provider** (C2/C3), which owns the active-sort → keyset-column resolution and
 * so is the only place the staleness can be detected. Surfaced as a `400` with
 * `source.parameter` naming the offending `page[…]` cursor parameter, distinct
 * from a {@see CursorMalformed} (a token that could not be decoded at all).
 */
final class CursorStale extends AbstractJsonApiException
{
    /**
     * @param string $parameter the cursor parameter that went stale, e.g. `page[after]` or `page[before]`
     */
    public function __construct(public readonly string $parameter)
    {
        parent::__construct("Cursor parameter '$parameter' no longer matches the requested sort!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'CURSOR_STALE',
                title: 'Cursor is stale',
                detail: "The cursor supplied in '$this->parameter' was built for a different sort order and can no longer be used.",
                source: ErrorSource::fromParameter($this->parameter),
            ),
        ];
    }
}
