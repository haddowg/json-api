<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter that compares a value reached through a dotted relationship path
 * ({@see WhereThrough}, {@see WhereThroughIn}). Until a {@see ThroughPathResolver}
 * resolves it against the relation graph, its path is read as ending on a field, as
 * an unresolved {@see WhereThrough} always was.
 */
interface TraversesPath extends FilterInterface
{
    /**
     * The declared dotted path, such as `campaign.advertiser`.
     */
    public function throughPath(): string;

    /**
     * The path resolved against the relation graph, or `null` before resolution.
     */
    public function resolvedPath(): ?ThroughPath;

    /**
     * A copy carrying `$path` as its resolution.
     *
     * @throws \LogicException when the filter cannot compare what the path ends on
     */
    public function withResolvedPath(ThroughPath $path): static;
}
