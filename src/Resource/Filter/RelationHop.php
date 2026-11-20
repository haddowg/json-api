<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * One relation a filter or sort reaches through: the relation as `$parentType`
 * declares it, and `$path`, the dotted storage path from the type the filter runs
 * against up to and including this hop. {@see ThroughReadAccess} lists them.
 *
 * A `pivot` hop reaches the pivot rows of `$relation`, the rows linking each parent to
 * its members, rather than the members. It is gated by `$relation`'s read permission.
 * A path that starts with `pivot` reaches the pivot row of the relation the filter or
 * sort runs under, one row for each member, so that hop is to-one; anywhere else a
 * pivot hop follows a to-many and reaches many rows.
 *
 * A hop of a polymorphic relation that names `$target` reaches only that one of its
 * types, as a {@see WhereMorph} filter does for the type a request names.
 */
final readonly class RelationHop
{
    public function __construct(
        public RelationInterface $relation,
        public string $parentType,
        public string $path,
        public bool $pivot = false,
        public ?string $target = null,
    ) {}

    /**
     * The type the relation reaches; a hop of a `->through()` path is monomorphic, except
     * a `pivot` hop, which reaches a pivot row and so names its relation's first type. A
     * hop naming its `$target` reaches that type.
     */
    public function relatedType(): string
    {
        return $this->target ?? $this->relation->relatedTypes()[0] ?? '';
    }

    /**
     * The types whose rows the hop can reach: its `$target` alone when it names one, and
     * every type of its relation otherwise. A gate decides the hop by these.
     *
     * @return list<string>
     */
    public function relatedTypes(): array
    {
        return $this->target !== null ? [$this->target] : $this->relation->relatedTypes();
    }

    /**
     * The member of the parent row that holds the related row or rows. For a `pivot` hop
     * it is the member holding the relation whose pivot rows the hop reaches.
     */
    public function storage(): string
    {
        return $this->relation->column() ?? $this->relation->name();
    }

    /**
     * This hop declared on `$parentType`.
     */
    public function on(string $parentType): self
    {
        return new self($this->relation, $parentType, $this->path, $this->pivot, $this->target);
    }
}
