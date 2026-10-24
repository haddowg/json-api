<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;

/**
 * A built document kept as value objects, with the {@see PruneBaseline} taken before any
 * serve-time decorator ran. An adapter writes one beside the JSON artifact at warm-up
 * (through {@see ServeArtifactCodec}), so a request that decorates the document skips both
 * the build and any JSON-to-value-object hydration.
 */
final readonly class ServeArtifact
{
    public function __construct(
        public OpenApi $document,
        public PruneBaseline $baseline,
    ) {}

    public static function of(OpenApi $document): self
    {
        return new self($document, ComponentPruner::baseline($document->toJson()));
    }

    /**
     * The JSON form of `$decorated` (this artifact's document after the decorators ran),
     * with whatever the decorators orphaned pruned against this artifact's baseline.
     */
    public function prune(OpenApi $decorated): \stdClass
    {
        return ComponentPruner::prune($decorated->toJson(), $this->baseline);
    }
}
