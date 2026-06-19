<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * The OpenAPI-relevant metadata for one relationship of a type — the subset of a
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface} the projector reads,
 * plus the bundle-side endpoint-exposure facts (which the core relation VO already
 * carries, but which the contract restates so a relation can be described without
 * a live resource — e.g. for a standalone-registered relation set).
 *
 * **Component projection (this slice)** uses {@see name()}, {@see relatedTypes()}
 * and {@see isToMany()} to emit the per-relationship object schema (linkage +
 * polymorphic `oneOf`). The remaining accessors describe the relationship/related
 * **endpoints** and their parameters, consumed by the Slice-3 path projection; they
 * are defined now so the contract is stable.
 */
interface RelationMetadataInterface
{
    /**
     * The relationship member name (the key under `relationships`, and the URI
     * segment for its related/relationship endpoints).
     */
    public function name(): string;

    /**
     * The allowed related resource type(s): a single-element list for a monomorphic
     * relation, multiple for a polymorphic one (whose linkage is a `oneOf` of the
     * member identifiers).
     *
     * @return list<string>
     */
    public function relatedTypes(): array;

    /**
     * Whether this is a to-many relationship (linkage is an array of identifiers;
     * a to-one's linkage is a single identifier or `null`).
     */
    public function isToMany(): bool;

    /**
     * A human-readable description for the relationship, or `null`.
     */
    public function description(): ?string;

    /**
     * Whether this relation may be expanded into a compound document's `included`
     * (named in `?include`). Drives whether the relation contributes to the
     * `include` parameter's allowed paths.
     */
    public function isIncludable(): bool;

    /**
     * Whether the related-resource endpoint (`GET /{type}/{id}/{rel}`) is exposed.
     * A suppressed endpoint emits no path and no `related` link.
     */
    public function exposesRelatedEndpoint(): bool;

    /**
     * Whether the relationship-linkage endpoint
     * (`GET|PATCH|POST|DELETE /{type}/{id}/relationships/{rel}`) is exposed.
     */
    public function exposesRelationshipEndpoint(): bool;

    /**
     * Whether full replacement (`PATCH` the relationship, or a `data` member in a
     * whole-resource write) is permitted — gates the relationship-mutation request
     * body in the Slice-3 path projection.
     */
    public function allowsReplace(): bool;

    /**
     * Whether additions (`POST` to a to-many relationship endpoint) are permitted.
     */
    public function allowsAdd(): bool;

    /**
     * Whether removals (`DELETE` from a to-many relationship endpoint, or clearing a
     * to-one) are permitted.
     */
    public function allowsRemove(): bool;

    /**
     * Whether this (to-many) relation is countable — its related-collection endpoint
     * advertises `?withCount` and emits the pagination `total` + `last` link.
     */
    public function isCountable(): bool;

    /**
     * The pagination strategy for this relation's related-collection endpoint
     * (already resolved against the related-resource / server fallback by the
     * metadata source). {@see PaginatorKind::None} for a to-one relation or an
     * explicitly unpaginated to-many.
     */
    public function paginatorKind(): PaginatorKind;

    /**
     * Extra filters this relation exposes on its related-collection endpoint
     * (`GET /{type}/{id}/{rel}`), scoped to that one relationship. Consumed by the
     * Slice-3 parameter projection.
     *
     * @return list<FilterInterface>
     */
    public function filters(): array;

    /**
     * Extra sorts this relation exposes on its related-collection endpoint, scoped to
     * that one relationship. Consumed by the Slice-3 parameter projection.
     *
     * @return list<SortInterface>
     */
    public function sorts(): array;

    /**
     * The `?include` paths valid on this relation's **related** endpoint
     * (`GET /{type}/{id}/{rel}`), as dotted paths relative to the **related** type —
     * not the parent's. That endpoint returns the related resource(s) as primary data,
     * so a valid `?include` enumerates the related type's includable relationships
     * (respecting its own include safeguards). Empty when nothing is includable there
     * (e.g. a polymorphic relation whose members share no include vocabulary).
     * Consumed by the Slice-3 related-endpoint `include` parameter projection.
     *
     * @return list<string>
     */
    public function relatedIncludablePaths(): array;
}
