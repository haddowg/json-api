<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `201 Created` — the created resource document (with a `Location` header). The
 * historic default create response.
 */
final readonly class Created implements CreateResponse
{
    public function status(): int
    {
        return 201;
    }

    public function jobType(): ?string
    {
        return null;
    }
}
