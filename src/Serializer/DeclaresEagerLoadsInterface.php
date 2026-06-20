<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * An opt-in capability a {@see SerializerInterface} MAY implement to declare the
 * relationships a host should **eager-load** before serializing this resource —
 * a load-not-render hint, distinct from `?include` (which renders into
 * `included`).
 *
 * A data-layer host reads it via `instanceof DeclaresEagerLoadsInterface` (it is
 * NOT part of {@see SerializerInterface}, so a standalone bare serializer with no
 * field inventory is tolerated: it declares no eager loads). This mirrors how the
 * server reads {@see DeclaresFieldNamesInterface} / {@see CountableControlsInterface}.
 *
 * The declared set is the to-one relation chains whose final related model must be
 * materialised to serialize correctly — every chain an `on()` attribute flattens a
 * scalar from (`'author'` or `'publisher.country'`). Core only **declares** it; the
 * host executes the loading (and excludes the eager set from `included` —
 * eager-loading changes only the query plan, never the document). The eager set is
 * author-declared and trusted, so a host MAY bypass the client-include safeguards
 * (depth cap / allowed-paths / `cannotBeIncluded`) for it.
 *
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements this from its field
 * inventory, so every Resource subclass satisfies the interface automatically.
 */
interface DeclaresEagerLoadsInterface
{
    /**
     * The to-one relation chains a host should eager-load before serializing this
     * resource — the dedup set of every `on()` attribute's backing relation chain
     * (`'author'` or the dotted `'publisher.country'`). Load-not-render: these
     * relations are materialised but never expanded into `included`. Default: empty.
     *
     * @return list<string>
     */
    public function eagerLoadRelationshipPaths(): array;
}
