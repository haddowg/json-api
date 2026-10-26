<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * An optional extension of {@see ActionMetadataInterface} for an action that accepts
 * request bodies in more than one media type, or one other than its input mode's
 * default. Each {@see AcceptedBody} becomes its own `requestBody.content` entry, and
 * the operation advertises the `415` a mismatched `Content-Type` gets.
 *
 * An action that does not implement it, or returns no bodies, projects its request body
 * from its input mode as before.
 */
interface DeclaresAcceptedBodies
{
    /**
     * The accepted request bodies, in the order the document lists them.
     *
     * @return list<AcceptedBody>
     */
    public function accepts(): array;
}
