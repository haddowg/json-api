<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

/**
 * A filter or sort declared with `->through()`: it reads a field of the type a path of
 * relations reaches rather than one of the type it runs against. Until a
 * {@see \haddowg\JsonApi\Resource\Filter\ThroughPathResolver} resolves it, a data layer
 * cannot apply it.
 *
 * The path names relations by their API names, `campaign.advertiser`, and the filter's
 * column or the sort's field names a field of the type the last relation reaches.
 */
interface ReachesThrough
{
    /**
     * The declared path of relations, or `null` for one that reads its own type.
     */
    public function throughRelations(): ?string;

    /**
     * The path resolved against the relation graph, or `null` before resolution.
     */
    public function resolvedThrough(): ?RelationPath;

    /**
     * A copy carrying `$path` as its resolution, with the field it reads checked.
     *
     * @throws \LogicException when the path's reached type has no field this can read
     */
    public function withResolvedThrough(RelationPath $path): static;

    /**
     * What applies to each row the resolved path reaches: a copy with no path, reading
     * the leaf field's storage column.
     *
     * @throws \LogicException when the path has not been resolved
     */
    public function atLeaf(): static;
}
