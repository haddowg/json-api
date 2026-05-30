<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request\Pagination;

// TODO(phase-2): folds into Page value object

final readonly class PageBasedPagination
{
    public function __construct(
        public int $page,
        public int $size,
    ) {}

    /**
     * @param array<string, mixed> $paginationQueryParams
     */
    public static function fromPaginationQueryParams(
        array $paginationQueryParams,
        int $defaultPage = 0,
        int $defaultSize = 0,
    ): self {
        return new self(
            self::extractInt($paginationQueryParams, 'number', $defaultPage),
            self::extractInt($paginationQueryParams, 'size', $defaultSize),
        );
    }

    public static function getPaginationQueryString(int $page, int $size): string
    {
        return \http_build_query(self::getPaginationQueryParams($page, $size));
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function getPaginationQueryParams(int $page, int $size): array
    {
        return [
            'page' => [
                'number' => $page,
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
