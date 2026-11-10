<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

/**
 * One member a host supplies for a {@see \haddowg\JsonApi\Resource\Field\MorphToMany}
 * declared with `identifiedBy()`, as an element of a {@see RelationshipLinkage}'s
 * `data`.
 *
 * `$type` and `$id` are the stored pair, with `$id` the storage key, and render as the
 * member's linkage. `$target` is the object `included` renders, with the serializer of
 * `$type`. Without one, core reads the relation's usual value off the parent and
 * renders the object there that the pair identifies.
 *
 * `$row` is the link row the pair was read from, when the relation declares pivot
 * fields: core reads the member's `meta.pivot` off it, and the in-memory link-row query
 * filters and sorts on it. A host querying link rows itself supplies each member with
 * its row.
 */
final readonly class IdentifiedMember
{
    public function __construct(
        public string $type,
        public string|int $id,
        public ?object $target = null,
        public mixed $row = null,
    ) {}

    /**
     * The member a stored `[type, id]` pair identifies, with its target when the host
     * loaded it and the link row it was read from when the relation has one.
     *
     * @param array{0: string, 1: string|int} $pair
     */
    public static function of(array $pair, ?object $target = null, mixed $row = null): self
    {
        return new self($pair[0], $pair[1], $target, $row);
    }

    /**
     * The same member with `$target` as the object `included` renders, keeping its row.
     */
    public function withTarget(?object $target): self
    {
        return new self($this->type, $this->id, $target, $this->row);
    }
}
