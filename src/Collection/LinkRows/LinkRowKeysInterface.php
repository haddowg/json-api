<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;

/**
 * Reads which members a batch of parents link, by key alone, for a {@see MorphToMany}
 * declared with `identifiedByRows()`: what a host needs before it narrows a type a scope
 * may hide rows of ({@see LinkRowQuery::narrowed()}).
 *
 * A data layer implements it beside its {@see LinkRowQueryInterface} with one key-only
 * read of the `through` class for the whole batch, selecting the owner, type and id
 * fields of the relation's {@see \haddowg\JsonApi\Resource\Field\LinkRowFields} and
 * nothing else: rows whose owner is one of the parents and whose type field holds a
 * stored value of one of `$types`
 * ({@see \haddowg\JsonApi\Resource\Field\LinkRowFields::storedValues()}).
 * {@see InMemoryLinkRows} is the reference.
 */
interface LinkRowKeysInterface
{
    /**
     * Each parent's distinct storage keys for each of `$types` it links, keyed by the
     * parent's position in `$parents`, every position present. Within a parent, keys are
     * grouped by JSON:API type in `$types` order, each type's keys in first-seen order,
     * and a type the parent links no member of is absent.
     *
     * @param list<mixed>  $parents
     * @param list<string> $types   JSON:API types the relation declares
     *
     * @return array<int, array<string, list<string|int>>>
     */
    public function linkRowKeys(array $parents, MorphToMany $relation, array $types, JsonApiRequestInterface $request): array;
}
