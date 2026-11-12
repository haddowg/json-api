<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that says whether the type's data layer refuses a client-generated id that
 * is already taken with `CLIENT_GENERATED_ID_ALREADY_EXISTS`, rather than letting the
 * store's own error through.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}.
 * Metadata that doesn't implement it offers the code on every create taking a client id.
 */
interface DeclaresClientIdCollisions extends TypeMetadataInterface
{
    /**
     * Whether a create naming an id that already exists answers
     * `CLIENT_GENERATED_ID_ALREADY_EXISTS`.
     */
    public function detectsClientIdCollisions(): bool;
}
