<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A relationship member of a resource's field inventory. Distinct from an
 * attribute {@see FieldInterface}: it serializes to a JSON:API relationship object
 * (linkage + links + meta) via the related type's serializer, and hydrates from
 * the request's parsed relationship value object rather than a raw attribute
 * value.
 *
 * The schema base routes relations through {@see buildRelationship()} and
 * {@see hydrateRelationship()} instead of the attribute serialize/hydrate path.
 */
interface RelationInterface extends \haddowg\JsonApi\Resource\Field\FieldInterface
{
    /**
     * The allowed related resource type(s). A single-element list for a
     * monomorphic relation; multiple for a polymorphic ({@see MorphTo}) one.
     *
     * @return list<string>
     */
    public function relatedTypes(): array;

    /**
     * Whether this is a to-many relationship.
     */
    public function isToMany(): bool;

    /**
     * Whether the relationship should be eager-loaded by data-layer adapters.
     * Core ships metadata only; the flag is advisory.
     */
    public function canEagerLoad(): bool;

    /**
     * Whether the relationship object should carry the spec's conventional
     * `self` / `related` links, built from the owning resource's type + id and
     * this relation's URI segment. On by default; suppressed by
     * {@see AbstractRelation::withoutLinks()}.
     */
    public function includesLinks(): bool;

    /**
     * Whether this relation only emits linkage `data` when the related value is
     * already loaded (a load-aware policy: emit links-only rather than trigger a
     * lazy storage load just to serialize identifiers). Off by default; enabled
     * by {@see AbstractRelation::linkageOnlyWhenLoaded()}. The policy is
     * advisory and gated by an injected
     * {@see \haddowg\JsonApi\Serializer\RelationshipLoadStateInterface}; an
     * included relationship always emits data, and a relation with no links
     * always emits data (never an empty relationship object).
     */
    public function emitsLinkageOnlyWhenLoaded(): bool;

    /**
     * Reads the related domain value(s) off the parent `$model` **without
     * serializing** — a single related object (or `null`) for a to-one relation,
     * an iterable of related objects for a to-many one. This is the linkage data
     * the relationship serializes from, exposed directly so a data-layer adapter
     * driving the related / relationship endpoints can hand the related domain
     * value(s) to the related type's provider without going through the
     * serializer.
     */
    public function readValue(mixed $model, JsonApiRequestInterface $request): mixed;

    /**
     * Builds the output relationship value object the serializer emits for
     * `$model`, resolving the related type's serializer through `$resolver`.
     */
    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship;

    /**
     * Resolves the serializer that renders the given related object as a FULL resource,
     * among this relation's declared types: the single declared type for a monomorphic
     * relation, or — for a polymorphic ({@see MorphTo}) relation — the declared type
     * whose serializer reports the object's own type ({@see SerializerInterface::getType()}).
     * For a null related value there is no object to discriminate, so the first declared,
     * registered serializer is returned (the caller renders `data: null`). Null when the
     * relation declares no registered type, or (polymorphic) none matches the object.
     */
    public function resolveSerializer(mixed $related, SerializerResolverInterface $resolver): ?SerializerInterface;

    /**
     * Hydrates the relationship from the request's parsed linkage into `$model`,
     * returning the (possibly replaced) domain object. Replaces the relationship
     * wholesale (the {@see Mode::Replace} baseline) — the path used when this
     * relation appears inside a whole-resource POST/PATCH body.
     *
     * @param \haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship|\haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship $relationship
     */
    public function hydrateRelationship(mixed $model, object $relationship): mixed;

    /**
     * Whether full replacement of this relationship is permitted (a `PATCH` to the
     * relationship endpoint, or a `data` member for this relation in a
     * whole-resource body). On by default; opt out via
     * {@see AbstractRelation::cannotReplace()}. A prohibited replacement is a
     * {@see \haddowg\JsonApi\Exception\FullReplacementProhibited} (403).
     */
    public function allowsReplace(): bool;

    /**
     * Whether removal from this relationship is permitted — `DELETE` to a to-many
     * relationship endpoint, or clearing a to-one (`data: null`). On by default;
     * opt out via {@see AbstractRelation::cannotRemove()}. A prohibited removal is
     * a {@see \haddowg\JsonApi\Exception\RemovalProhibited} (403).
     */
    public function allowsRemove(): bool;

    /**
     * Whether this relation's related HTTP endpoint (`GET /{type}/{id}/{rel}`) is
     * exposed. On by default; suppressed by
     * {@see AbstractRelation::withoutRelatedEndpoint()}. A suppressed endpoint is
     * enforced by the host as a 404, and its conventional `related` link is omitted
     * so a rendered link never points at that 404.
     */
    public function exposesRelatedEndpoint(): bool;

    /**
     * Whether this relation's relationship-linkage HTTP endpoint
     * (`GET|PATCH|POST|DELETE /{type}/{id}/relationships/{rel}`) is exposed. On by
     * default; suppressed by {@see AbstractRelation::withoutRelationshipEndpoint()}.
     * A suppressed endpoint is enforced by the host as a 404, and its conventional
     * `self` link is omitted so a rendered link never points at that 404.
     */
    public function exposesRelationshipEndpoint(): bool;

    /**
     * Whether additions to this (to-many) relationship are permitted — a `POST` to
     * its relationship endpoint. On by default; opt out via
     * {@see AbstractRelation::cannotAdd()}. A prohibited addition is an
     * {@see \haddowg\JsonApi\Exception\AdditionProhibited} (403).
     */
    public function allowsAdd(): bool;

    /**
     * Whether this relationship may be included in a compound document — fetched
     * via `?include` and expanded into `included`, and auto-included when it is a
     * default-include. On by default; opt out via
     * {@see AbstractRelation::cannotBeIncluded()}. A `?include` naming a
     * non-includable relation is an
     * {@see \haddowg\JsonApi\Exception\InclusionNotAllowed} (400); a non-includable
     * relation is also excluded from the default-include cascade. Linkage and
     * `self` / `related` links are unaffected.
     */
    public function isIncludable(): bool;

    /**
     * This relation's declared default paginator for its related-collection
     * endpoint (`GET /{type}/{id}/{rel}`), or `null` for none. Set via
     * {@see AbstractRelation::paginate()}. It is the to-many related-endpoint
     * paginator — a to-one relation has no collection and ignores it; the host
     * resolves the effective strategy as relation → related-resource → server
     * default.
     */
    public function pagination(): ?\haddowg\JsonApi\Pagination\PaginatorInterface;

    /**
     * Extra filters this relation exposes **only** on its related-collection
     * endpoint (`GET /{type}/{id}/{rel}`) — scoped to that one relationship, not
     * the primary collection of the related type. Mirrors how a resource declares
     * {@see \haddowg\JsonApi\Resource\AbstractResource::filters()}; the host merges
     * this set with the related resource's own filters when parsing the request's
     * `?filter`, and on a key clash the relation's declaration wins (the more
     * specific scope). Declared via {@see AbstractRelation::withFilters()}.
     * Default: none. Metadata only — execution lives in the adapter's filter
     * handler, exactly as for a resource filter.
     *
     * @return list<\haddowg\JsonApi\Resource\Filter\FilterInterface>
     */
    public function filters(): array;

    /**
     * Extra sorts this relation exposes **only** on its related-collection
     * endpoint (`GET /{type}/{id}/{rel}`) — scoped to that one relationship, not
     * the primary collection of the related type. Mirrors how a resource declares
     * {@see \haddowg\JsonApi\Resource\AbstractResource::sorts()}; the host merges
     * this set with the related resource's own sorts when parsing the request's
     * `?sort`, and on a key clash the relation's declaration wins (the more
     * specific scope). Declared via {@see AbstractRelation::withSorts()}. Default:
     * none. Metadata only — execution lives in the adapter's sort handler, exactly
     * as for a resource sort.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    public function sorts(): array;

    /**
     * Applies parsed to-many linkage to `$model` under `$mode`: {@see Mode::Replace}
     * sets the whole set, {@see Mode::Add} appends the linkage ids to the existing
     * set, {@see Mode::Remove} subtracts them. The storage-agnostic baseline writes
     * the resulting id list onto the field's column; a data-layer override mutates
     * the real association.
     *
     * @param \haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship $relationship
     */
    public function applyToMany(mixed $model, object $relationship, Mode $mode): mixed;
}
