<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says whether the server validates query parameters strictly, which
 * decides whether it refuses an unknown `fields[<type>]` member with
 * `FIELDSET_MEMBER_UNRECOGNIZED`.
 *
 * A separate, opt-in contract rather than a method on {@see ServerMetadataInterface}, the
 * same shape as {@see DeclaresReadOnlyAttributeRejection}. Metadata that doesn't implement
 * it describes a strict server, which is the default.
 */
interface DeclaresStrictQueryParameters extends ServerMetadataInterface
{
    /**
     * Mirrors {@see \haddowg\JsonApi\Server\Server::strictQueryParameters()}.
     */
    public function strictQueryParameters(): bool;
}
