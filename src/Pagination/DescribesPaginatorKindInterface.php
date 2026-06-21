<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;

/**
 * An opt-in capability a {@see PaginatorInterface} MAY implement to self-describe
 * the OpenAPI {@see PaginatorKind} of its `page[…]` query parameters, so the
 * OpenAPI generator can enumerate the right parameters without `instanceof`-ing
 * the concrete strategy.
 *
 * The metadata source (the bundle) reads it via
 * `instanceof DescribesPaginatorKindInterface` — it is NOT part of
 * {@see PaginatorInterface}, so a strategy that does not implement it is tolerated:
 * the bundle falls back to its class-map of the built-in strategies, and an
 * unrecognized custom paginator is projected as the JSON:API-conventional
 * count-based {@see PaginatorKind::Page} rather than failing to generate. This
 * mirrors how the transformer reads {@see RelationshipCountInterface} and how the
 * server reads {@see \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface}.
 *
 * The built-in strategies all implement it: {@see PagePaginator} and
 * {@see FixedPagePaginator} return {@see PaginatorKind::Page}, {@see OffsetPaginator}
 * returns {@see PaginatorKind::Offset}, and {@see CursorPaginator} returns
 * {@see PaginatorKind::Cursor}. A custom paginator with bespoke `page[…]` keys
 * implements this to advertise which built-in shape its parameters match (or
 * {@see PaginatorKind::None} when its collection is effectively unpaginated).
 */
interface DescribesPaginatorKindInterface
{
    /**
     * The OpenAPI {@see PaginatorKind} this strategy's `page[…]` parameters match,
     * read by the OpenAPI generator to enumerate the right query parameters.
     */
    public function paginatorKind(): PaginatorKind;
}
