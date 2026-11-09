<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;

/**
 * A relation that can fix its members with
 * {@see AbstractRelationBuilder::cannotChangeMembership()}. Every core relation
 * implements it; a separate interface keeps a hand-written
 * {@see RelationInterface} implementation compiling.
 *
 * Core cannot see a data layer's current members, so a host replacing a to-many
 * reads them and calls {@see assertMembershipUnchanged()} before writing.
 */
interface GuardsMembershipInterface extends RelationInterface
{
    /**
     * Whether a replacement may change this relation's members **unconditionally**.
     * `false` after `cannotChangeMembership()` with no closure, and for a relation
     * that is unconditionally read-only on update. A closure-gated relation reports
     * `true` here and is judged per request by {@see allowsMembershipChangeFor()}.
     */
    public function allowsMembershipChange(): bool;

    /**
     * Whether the relation declares `cannotChangeMembership()` at all, unconditionally or
     * with a closure, so a replacement may be refused for changing its members. Metadata
     * reports it through {@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresFixedMembership}.
     */
    public function fixesMembership(): bool;

    /**
     * Whether a replacement may change this relation's members **for this request**:
     * the unconditional flag, the relation not read-only on update for the request,
     * and, when a closure was declared, its negation against `$model` and the request.
     */
    public function allowsMembershipChangeFor(JsonApiRequestInterface $request, mixed $model): bool;

    /**
     * Rejects a replacement whose member set differs from `$current`, ignoring order
     * and duplicates, when this request may not change the members. A member sent
     * with only a `lid` is new, so it always counts as a change.
     *
     * @param iterable<ResourceIdentifier|array{0: string, 1: string|int}> $current the members the parent holds now, as identifiers or `[type, id]` pairs with the wire id
     *
     * @throws \haddowg\JsonApi\Exception\MembershipChangeProhibited when the members would change (403)
     */
    public function assertMembershipUnchanged(JsonApiRequestInterface $request, mixed $model, iterable $current, ToManyRelationship $incoming): void;
}
