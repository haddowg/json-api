<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A relation that says which of its mutation and inclusion guards rest on a predicate,
 * the relation counterpart of {@see ConditionalFieldInterface}. Every core relation
 * implements it; a hand-written {@see RelationInterface} that does not is read as
 * possibly conditional.
 */
interface ConditionalRelationInterface extends RelationInterface, ConditionalFieldInterface
{
    /**
     * Whether a predicate can refuse a relationship mutation in `$mode` that the
     * unconditional flags ({@see allowsReplace()}, {@see allowsAdd()},
     * {@see allowsRemove()}) permit: the mode's own `cannotReplace(when:)`,
     * `cannotAdd(when:)` or `cannotRemove(when:)`, a `cannotChangeMembership(when:)`
     * for an addition or a removal, or a `readOnly(when:)` on update.
     */
    public function hasMutationPredicate(Mode $mode): bool;

    /**
     * Whether a `cannotBeIncluded(when:)` predicate decides whether the relation can be
     * included.
     */
    public function hasInclusionPredicate(): bool;
}
