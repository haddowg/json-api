<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Serve;

/**
 * What a document referenced before any serve-time decorator ran: the components reachable
 * from outside `components`, and the tags its operations used. Only these are candidates
 * for {@see ComponentPruner::prune()}.
 */
final readonly class PruneBaseline
{
    /**
     * @param array<string, true> $reachable     component pointers, e.g. `#/components/schemas/Album`
     * @param array<string, true> $operationTags tag names used by at least one operation
     */
    public function __construct(
        public array $reachable,
        public array $operationTags,
    ) {}
}
