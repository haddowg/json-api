<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * The pagination strategy a type's collection endpoint uses, as seen by the
 * OpenAPI projector — the discriminator that selects which concrete `page[…]`
 * query parameters are enumerated (Slice 3).
 *
 * Core's {@see \haddowg\JsonApi\Pagination\PaginatorInterface} carries no
 * self-description, so the metadata source (the bundle, Slice 4) discriminates
 * the resolved paginator and reports the kind here. {@see None} means the type's
 * collection is unpaginated (fetch-all) — no `page[…]` parameters at all.
 */
enum PaginatorKind: string
{
    /** `page[number]` + `page[size]` (the count-based page strategy). */
    case Page = 'page';

    /** `page[offset]` + `page[limit]`. */
    case Offset = 'offset';

    /** `page[cursor]` + `page[size]` (keyset / cursor pagination). */
    case Cursor = 'cursor';

    /** No pagination — the collection is fetched whole, no `page[…]` parameters. */
    case None = 'none';
}
