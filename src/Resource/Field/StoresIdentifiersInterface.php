<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A relation that can read its targets' `[type, id]` pairs off the parent, so a host
 * can load the targets of a page of parents in one query per type.
 * {@see MorphTo} and {@see MorphToMany} implement it, and do so when declared with
 * `identifiedBy()`.
 */
interface StoresIdentifiersInterface extends RelationInterface
{
    /**
     * Whether the relation was declared with `identifiedBy()`, so its targets'
     * identifiers are read off the parent.
     */
    public function storesIdentifiers(): bool;

    /**
     * The stored target ids of every model in `$models`, grouped by type in declared
     * order, each type's ids deduplicated in first-seen order and empty types left
     * out. Ids are storage keys, ready for one `id IN (...)` query per type.
     *
     * @param iterable<mixed> $models
     *
     * @return array<string, non-empty-list<string|int>>
     *
     * @throws \LogicException when the relation stores no identifiers, or a pair is
     *                         malformed or names a type the relation does not declare
     */
    public function storedIdentifiers(iterable $models, JsonApiRequestInterface $request): array;
}
