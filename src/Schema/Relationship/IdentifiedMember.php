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
 */
final readonly class IdentifiedMember
{
    public function __construct(
        public string $type,
        public string|int $id,
        public ?object $target = null,
    ) {}

    /**
     * The member a stored `[type, id]` pair identifies, with its target when the host
     * loaded it.
     *
     * @param array{0: string, 1: string|int} $pair
     */
    public static function of(array $pair, ?object $target = null): self
    {
        return new self($pair[0], $pair[1], $target);
    }
}
