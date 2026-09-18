<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

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
final class CursorStale extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string $parameter the cursor parameter that went stale, e.g. `page[after]` or `page[before]`
     */
    public function __construct(public readonly string $parameter)
    {
        parent::__construct("Cursor parameter '$parameter' no longer matches the requested sort!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'CURSOR_STALE',
            status: 400,
            title: 'Cursor is stale',
            context: ['parameter' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            feature: ErrorFeature::CursorPagination,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "The cursor supplied in '$this->parameter' was built for a different sort order and can no longer be used.",
                context: ['parameter' => $this->parameter],
                source: ErrorSource::fromParameter($this->parameter),
            ),
        ];
    }
}
