<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request\Pagination;

// TODO(phase-2): folds into Page value object

final readonly class CursorBasedPagination
{
    public function __construct(
        public mixed $cursor,
        public int $size = 0,
    ) {}

    /**
     * @param array<string, mixed> $paginationQueryParams
     */
    public static function fromPaginationQueryParams(
        array $paginationQueryParams,
        mixed $defaultCursor = null,
        int $defaultSize = 0,
    ): self {
        return new self(
            $paginationQueryParams['cursor'] ?? $defaultCursor,
            self::extractInt($paginationQueryParams, 'size', $defaultSize),
        );
    }

    public static function getPaginationQueryString(mixed $cursor, int $size): string
    {
        return \http_build_query(self::getPaginationQueryParams($cursor, $size));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getPaginationQueryParams(mixed $cursor, int $size): array
    {
        return [
            'page' => [
                'cursor' => $cursor,
                'size' => $size,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function extractInt(array $params, string $key, int $default): int
    {
        return isset($params[$key]) && \is_numeric($params[$key]) ? (int) $params[$key] : $default;
    }
}
