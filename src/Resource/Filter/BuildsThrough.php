<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The `->through()` modifier on the filter builders.
 *
 * @internal
 */
trait BuildsThrough
{
    protected ?string $through = null;

    /**
     * Reads the filter's column on the type a path of relations reaches, named by their
     * API names: `->through('campaign')`, or `->through('campaign.advertiser')` for
     * several hops. The column names a field of that type. A row matches when a related
     * row exists and matches; a relation that holds nothing never matches.
     *
     * @return static
     */
    public function through(string $path): static
    {
        $this->through = \haddowg\JsonApi\Resource\Through\ThroughPaths::checked($path);

        return $this;
    }
}
