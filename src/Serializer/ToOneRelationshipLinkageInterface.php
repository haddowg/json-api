<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;

/**
 * The to-one counterpart of {@see RelationshipLinkageInterface}: supplies, per
 * (parent model, to-one relation), the related object core renders instead of the one
 * it would read off the parent. A host that loads related rows through its own path
 * (a scoped query, a custom data provider) hands core that row, or none, without
 * writing anything onto the parent.
 *
 * It is opt-in. Inject an implementation through
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipLinkage()} like any
 * {@see RelationshipLinkageInterface}; core checks for this interface on the injected
 * object, so a resolver that only delegates `relationshipLinkage()` passes it through
 * unchanged. An implementation with nothing to supply for to-many relations returns
 * `null` from {@see RelationshipLinkageInterface::linkageForRelationship()}.
 *
 * Core consults it for every to-one relation ({@see \haddowg\JsonApi\Resource\Field\BelongsTo},
 * {@see \haddowg\JsonApi\Resource\Field\HasOne}, {@see \haddowg\JsonApi\Resource\Field\MorphTo}),
 * hidden ones included, wherever it reads the related object:
 *
 * - the relationship's linkage `data`, which also drives `included` and the
 *   `/relationships/{name}` document;
 * - each hop of an `on()` flattened attribute, on both read and write.
 *
 * It does not change {@see RelationInterface::readValue()}, which an adapter calls
 * itself (for the related endpoint, say) and so already controls.
 *
 * A relation whose linkage is deferred by the load-state predicate still defers: the
 * seam is consulted only when core would otherwise read the parent.
 *
 * For a {@see \haddowg\JsonApi\Resource\Field\MorphTo} declared with `identifiedBy()`,
 * the stored `[type, id]` pair stays the linkage. A supplied target is the object
 * `included` renders, with the serializer of the pair's type, and a supplied none
 * empties the relation. A target supplied for an empty pair renders nothing. Return
 * `null` to keep the pair and let core read the target only if it is included.
 */
interface ToOneRelationshipLinkageInterface extends RelationshipLinkageInterface
{
    /**
     * The target to render for to-one `$relation` on `$model`, or `null` to read it
     * off the parent as usual. Return {@see ToOneRelationshipLinkage::none()} to render
     * the relation as empty. For a {@see \haddowg\JsonApi\Resource\Field\MorphTo} the
     * target's own type picks its serializer from the relation's declared types; a
     * target matching none of them renders as none. An identified `MorphTo` uses its
     * stored pair's type instead.
     */
    public function toOneLinkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?ToOneRelationshipLinkage;
}
