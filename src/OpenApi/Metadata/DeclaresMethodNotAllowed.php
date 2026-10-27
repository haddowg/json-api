<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says whether a request using a method one of the server's paths
 * does not accept is answered with a JSON:API error document, so the document catalogues
 * the `METHOD_NOT_ALLOWED` code it can then raise.
 *
 * A separate, opt-in contract rather than a method on {@see ServerMetadataInterface},
 * the same shape as {@see DeclaresReadOnlyAttributeRejection}. Core routes nothing, so only
 * an adapter that owns routing can raise the code; metadata that doesn't implement this
 * describes a server that leaves such requests to its framework.
 */
interface DeclaresMethodNotAllowed extends ServerMetadataInterface
{
    /**
     * Whether a method the path does not accept renders as a `405` `METHOD_NOT_ALLOWED`
     * error document.
     */
    public function rendersMethodNotAllowed(): bool;
}
