<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * A relationship member of a resource's field inventory. Distinct from an
 * attribute {@see Field}: it serializes to a JSON:API relationship object
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
     * Builds the output relationship value object the serializer emits for
     * `$model`, resolving the related type's serializer through `$resolver`.
     */
    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship;

    /**
     * Hydrates the relationship from the request's parsed linkage into `$model`,
     * returning the (possibly replaced) domain object.
     *
     * @param \haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship|\haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship $relationship
     */
    public function hydrateRelationship(mixed $model, object $relationship): mixed;
}
