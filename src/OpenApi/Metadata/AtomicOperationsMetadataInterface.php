<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\SecurityRequirement;

/**
 * The OpenAPI-relevant metadata for a server's JSON:API **Atomic Operations**
 * extension endpoint (the opt-in `POST /operations` batch endpoint), when the
 * server has it enabled.
 *
 * The atomic endpoint is **not** per-type: a single endpoint accepts a batch of
 * operations spanning any of the server's registered types, applied in order and
 * all-or-nothing within one transaction. So this contract carries only the
 * endpoint-shaped metadata the projector cannot derive from the type list — the
 * mount path, the grouping tag and the per-operation security — while the
 * participating resource shapes (the polymorphic request/response `data` schemas)
 * are projected from {@see ServerMetadataInterface::types()}.
 *
 * A server that has not enabled the extension returns `null` from
 * {@see ServerMetadataInterface::atomicOperations()}, and the projector emits no
 * atomic path or components.
 *
 * @see \haddowg\JsonApi\Atomic\AtomicExtension
 * @see https://jsonapi.org/ext/atomic/
 */
interface AtomicOperationsMetadataInterface
{
    /**
     * The path the atomic endpoint is mounted at (e.g. `/operations`), already
     * resolved by the metadata source. The projector emits one `POST` operation
     * here.
     */
    public function path(): string;

    /**
     * The OpenAPI tag the atomic operation is grouped under (e.g.
     * `Atomic Operations`). The projector emits this on the operation and unions it
     * into the document-root tag set so it is defined.
     */
    public function tag(): string;

    /**
     * The security requirement (OR-ed alternatives) for the atomic operation, or an
     * empty list to inherit the document-level default. Mirrors how
     * {@see TypeMetadataInterface::securedOperations()} drives the per-operation
     * security on CRUD endpoints: the requirement VOs come from the configured
     * security, never from parsing an authz expression.
     *
     * @return list<SecurityRequirement>
     */
    public function security(): array;
}
