<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request\Pagination;

// TODO(phase-2): folds into Page value object

final readonly class FixedCursorBasedPagination
{
    public function __construct(
        public mixed $cursor,
    ) {}

    /**
     * @param array<string, mixed> $paginationQueryParams
     */
    public static function fromPaginationQueryParams(
        array $paginationQueryParams,
        mixed $defaultCursor = null,
    ): self {
        return new self(
            $paginationQueryParams['cursor'] ?? $defaultCursor,
        );
    }

    public static function getPaginationQueryString(mixed $cursor): string
    {
        return \http_build_query(self::getPaginationQueryParams($cursor));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getPaginationQueryParams(mixed $cursor): array
    {
        return [
            'page' => [
                'cursor' => $cursor,
            ],
        ];
    }
}
