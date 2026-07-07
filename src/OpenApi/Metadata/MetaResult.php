<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `200 OK` with a meta-only document (no primary `data`). Valid on delete — a delete
 * that answers with top-level `meta` rather than `204 No Content`.
 */
final readonly class MetaResult implements DeleteResponse, ActionResponse
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
