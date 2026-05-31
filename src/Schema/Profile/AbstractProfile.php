<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Convenience base for {@see ProfileInterface} implementations.
 *
 * Provides no-op defaults for {@see keywords()} (no reserved keywords) and
 * {@see finalizeDocument()} (identity). Authors implement {@see uri()} and
 * override the hooks they need. The contract remains implementable by
 * composition; this base is an ergonomic shortcut, not a requirement.
 */
abstract class AbstractProfile implements ProfileInterface
{
    public function keywords(): array
    {
        return [];
    }

    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
    {
        return $document;
    }
}
