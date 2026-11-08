<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Action metadata that lets a collection-scope action take the `filter[<key>]` query
 * parameters its mount type declares for its collection. The adapter parses, validates
 * and applies them as it does for a collection fetch, and the projector documents them on
 * the action's operation exactly as the collection fetch documents them.
 *
 * {@see filters()} returns `false` for none, `true` for every filter the type declares, or
 * a list of the filter keys to take. Only a collection-scope action may declare filters,
 * and a listed key must be one the type declares; {@see ActionDeclarations::validate()}
 * rejects anything else. {@see ActionDeclarations::filters()} resolves the filters an
 * action takes.
 *
 * A separate, opt-in contract rather than a method on {@see ActionMetadataInterface}, so
 * an existing implementation keeps working, the same shape as {@see DeclaresActionSafety}.
 */
interface DeclaresActionFilters extends ActionMetadataInterface
{
    /**
     * @return bool|list<string>
     */
    public function filters(): bool|array;
}
