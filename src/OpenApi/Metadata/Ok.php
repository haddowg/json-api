<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `200 OK` — a resource (or collection) document. The historic default response for
 * fetch-collection, fetch-one and update. A delete's `200` is {@see MetaResult}, not
 * this.
 */
final readonly class Ok implements UpdateResponse, FetchOneResponse, FetchCollectionResponse
{
    public function status(): int
    {
        return 200;
    }

    public function jobType(): ?string
    {
        return null;
    }
}
