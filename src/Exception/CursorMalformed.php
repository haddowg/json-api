<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A `page[after]` / `page[before]` cursor token could not be decoded — it is not
 * valid base64url, not valid JSON, or does not decode to the expected boundary
 * shape. Surfaced as a `400` with `source.parameter` naming the offending
 * `page[…]` cursor parameter, distinct from a {@see CursorStale} (a well-formed
 * token whose columns no longer match the active sort).
 */
final class CursorMalformed extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string $parameter the cursor parameter that was malformed, e.g. `page[after]` or `page[before]`
     */
    public function __construct(public readonly string $parameter)
    {
        parent::__construct("Cursor parameter '$parameter' is malformed!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'CURSOR_MALFORMED',
            status: 400,
            title: 'Cursor is malformed',
            context: ['parameter' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            feature: ErrorFeature::CursorPagination,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "The cursor supplied in '$this->parameter' could not be decoded.",
                context: ['parameter' => $this->parameter],
                source: ErrorSource::fromParameter($this->parameter),
            ),
        ];
    }
}
