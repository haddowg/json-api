<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request\Pagination;

// TODO(phase-2): folds into Page value object

final readonly class FixedPageBasedPagination
{
    public function __construct(
        public int $page,
    ) {}

    /**
     * @param array<string, mixed> $paginationQueryParams
     */
    public static function fromPaginationQueryParams(
        array $paginationQueryParams,
        int $defaultPage = 0,
    ): self {
        return new self(
            self::extractInt($paginationQueryParams, 'number', $defaultPage),
        );
    }

    public static function getPaginationQueryString(int $page): string
    {
        return \http_build_query(self::getPaginationQueryParams($page));
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function getPaginationQueryParams(int $page): array
    {
        return [
            'page' => [
                'number' => $page,
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
