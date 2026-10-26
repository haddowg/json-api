<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says whether the projector generates operation examples. A server
 * that does not implement it gets them.
 */
interface DeclaresExampleGeneration extends ServerMetadataInterface
{
    /**
     * `false` leaves every request body, response and shared error response without a
     * generated example, records no missing-example diagnostics, and ignores any
     * per-operation override.
     */
    public function generatesExamples(): bool;
}
