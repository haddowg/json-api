<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says whether the server rejects a read-only attribute sent in a
 * write body, so the document catalogues the `ATTRIBUTE_WRITE_PROHIBITED` code it can
 * then raise.
 *
 * A separate, opt-in contract rather than a method on {@see ServerMetadataInterface},
 * the same shape as {@see DeclaresLinkObjects}. Metadata that doesn't implement it
 * describes a server that ignores such attributes, which is the default.
 */
interface DeclaresReadOnlyAttributeRejection extends ServerMetadataInterface
{
    /**
     * Mirrors {@see \haddowg\JsonApi\Server\Server::withReadOnlyAttributesRejected()}.
     */
    public function rejectsReadOnlyAttributes(): bool;
}
