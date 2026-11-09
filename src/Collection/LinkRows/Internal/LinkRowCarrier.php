<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows\Internal;

use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * One member as the in-memory filter and sort handlers see it: reading a column, with or
 * without its `pivot.` prefix, reads that column off the member's link row.
 *
 * @internal
 *
 * @implements \ArrayAccess<string, mixed>
 */
final readonly class LinkRowCarrier implements \ArrayAccess
{
    public function __construct(public IdentifiedMember $member) {}

    public function offsetExists(mixed $offset): bool
    {
        return \is_string($offset) && $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return \is_string($offset) && $this->member->row !== null
            ? Accessor::get($this->member->row, LinkRowQuery::rowColumn($offset))
            : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('A link row is read-only while it is queried.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('A link row is read-only while it is queried.');
    }
}
