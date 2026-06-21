<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

/**
 * One parsed atomic operation: its code, its target (exactly one of a structural
 * {@see Ref} or a `href` string), its `data` payload, and its 0-based position in
 * the batch.
 *
 * The {@see $data} member is the operation's `data` verbatim — `mixed` because its
 * shape depends on the operation: a resource object for an `add`/`update` of a
 * resource, a single resource-identifier (or `null`) for a to-one relationship
 * `update`, a list of resource-identifiers for a to-many relationship operation,
 * or absent (`null`) for a `remove` of a resource. Interpreting `data` against the
 * resolved target is the executor's job; the parser only validates that the shape
 * is appropriate for the code.
 *
 * The {@see $index} carries the operation's position so the all-or-nothing loop can
 * prefix a failing operation's error pointers with `/atomic:operations/<index>`.
 *
 * This is a leaf value object: the readonly property is the accessor — no getters.
 */
final readonly class OperationDescriptor
{
    public function __construct(
        public AtomicOperationCode $opCode,
        public ?Ref $ref,
        public ?string $href,
        public mixed $data,
        public int $index,
    ) {}

    /**
     * Whether this operation targets its endpoint by a structural {@see Ref} (vs a
     * `href`). Exactly one of the two is present, so the negation answers `href`.
     */
    public function hasRef(): bool
    {
        return $this->ref !== null;
    }
}
