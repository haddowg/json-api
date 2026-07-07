<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `204 No Content` — a bodyless success. Valid on create (a client-generated id with
 * nothing to echo back), update (no server-side changes) and delete.
 */
final readonly class NoContent implements CreateResponse, UpdateResponse, DeleteResponse, ActionResponse
{
    public function status(): int
    {
        return 204;
    }

    public function jobType(): ?string
    {
        return null;
    }
}
