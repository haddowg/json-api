<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `303 See Other` — the asynchronous operation is complete; a `Location` header points
 * at the produced resource, no body. Valid on fetch-one; its runtime counterpart is
 * {@see \haddowg\JsonApi\Resource\ResolvesCompletionRedirect}.
 */
final readonly class SeeOther implements FetchOneResponse, ActionResponse
{
    public function status(): int
    {
        return 303;
    }

    public function jobType(): ?string
    {
        return null;
    }
}
