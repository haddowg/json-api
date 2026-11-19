<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Filter\RelationHop;

/**
 * Server metadata that says which relations a filter may not reach through for some
 * caller, so the error catalogue offers `ACCESS_DENIED` and `AUTHENTICATION_REQUIRED` on
 * every operation whose filters, or whose `relatedQuery` filters, walk such a relation.
 * The runtime decision is the adapter's {@see \haddowg\JsonApi\Resource\ThroughReadGateInterface};
 * this one answers for any caller.
 *
 * Opt-in, alongside {@see DeclaresOperationSecurity}: metadata that doesn't implement it
 * adds nothing for filters.
 */
interface DeclaresThroughReadSecurity extends ServerMetadataInterface
{
    /**
     * Whether a caller can be refused a filter that reaches through `$hop`.
     */
    public function canRefuseThrough(RelationHop $hop): bool;
}
