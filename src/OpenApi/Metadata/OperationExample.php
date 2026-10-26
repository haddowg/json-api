<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Replaces parts of the example the projector generates for one operation.
 *
 * `request` is the request body's example: leave it `null` to keep the generated one,
 * pass `false` to emit none, or pass the document to emit instead. `responses` maps a
 * success status to the document to emit under it, or to `false` for none; a status it
 * leaves out keeps its generated example. Each value lands on the operation's JSON:API
 * media type as written, so it is not checked against the schema.
 *
 * Declare it with `new` and constant arguments, so it can be written inside attribute
 * arguments.
 */
final readonly class OperationExample
{
    /**
     * @param array<int|string, mixed> $responses success status → example document, or `false` for none
     */
    public function __construct(
        public mixed $request = null,
        public array $responses = [],
    ) {}
}
