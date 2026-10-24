<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Host-supplied authorization for the relation data core reaches on its own, outside
 * the endpoints a host already guards: the linkage and included resources of a
 * rendered relationship, and the related model a flattened (`on()`) attribute writes
 * onto. Core can't evaluate a host's authorization rules, so it asks this instead.
 *
 * A {@see \haddowg\JsonApi\Server\Server} carries one through
 * {@see \haddowg\JsonApi\Server\Server::withRelationAuthorizer()}; with none, core
 * renders and writes as if every answer were `true`. A host typically evaluates the
 * relation's declared {@see RelationInterface::securityRead()} in
 * {@see canReadRelationship()}, so the rule that guards the relation's endpoints also
 * guards its data everywhere else.
 */
interface RelationAuthorizerInterface
{
    /**
     * Whether the caller may see `$relation`'s related data on the parent `$model`.
     * Asked once per rendered relationship, for primary and included resources alike.
     * When it answers `false` the relationship keeps its `links`, renders no `data`
     * or `meta`, and adds nothing to `included`; with no links either it is left out.
     */
    public function canReadRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): bool;

    /**
     * Whether the caller may write the flattened `$field` onto `$owner`, the related
     * model at the end of its `on()` chain. `$relation` is the chain's last hop, so
     * its {@see RelationInterface::relatedTypes()} names the owner's type, and
     * `$model` is the resource being hydrated. Asked before each write, after the
     * request body's relationships are applied, so `$owner` may be a model the same
     * body just linked. When it answers `false` the write is refused with
     * {@see \haddowg\JsonApi\Exception\AccessDenied}.
     */
    public function canWriteRelatedAttribute(mixed $owner, RelationInterface $relation, FieldInterface $field, mixed $model, JsonApiRequestInterface $request): bool;
}
