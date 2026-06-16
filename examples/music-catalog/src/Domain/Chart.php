<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A read-only chart, served by a standalone bare serializer
 * ({@see \haddowg\JsonApi\Examples\MusicCatalog\Serializer\ChartSerializer}) with
 * NO Resource and NO hydrator — the capability-composition witness.
 */
final class Chart
{
    /**
     * @param list<array{rank: int, trackId: string, plays: int}> $entries
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $period = '',
        public array $entries = [],
    ) {}
}
