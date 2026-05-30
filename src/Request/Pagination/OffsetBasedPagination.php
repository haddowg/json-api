<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request\Pagination;

// TODO(phase-2): folds into Page value object

final readonly class OffsetBasedPagination
{
    public function __construct(
        public int $offset,
        public int $limit,
    ) {}

    /**
     * @param array<string, mixed> $paginationQueryParams
     */
    public static function fromPaginationQueryParams(
        array $paginationQueryParams,
        int $defaultOffset = 0,
        int $defaultLimit = 0,
    ): self {
        return new self(
            self::extractInt($paginationQueryParams, 'offset', $defaultOffset),
            self::extractInt($paginationQueryParams, 'limit', $defaultLimit),
        );
    }

    public static function getPaginationQueryString(int $offset, int $limit): string
    {
        return \http_build_query(self::getPaginationQueryParams($offset, $limit));
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function getPaginationQueryParams(int $offset, int $limit): array
    {
        return [
            'page' => [
                'offset' => $offset,
                'limit' => $limit,
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
