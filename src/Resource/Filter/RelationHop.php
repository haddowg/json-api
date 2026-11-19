<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * One relation a filter or sort reaches through: the relation as `$parentType`
 * declares it, and `$path`, the dotted storage path from the type the filter runs
 * against up to and including this hop. {@see ThroughReadAccess} lists them.
 */
final readonly class RelationHop
{
    public function __construct(
        public RelationInterface $relation,
        public string $parentType,
        public string $path,
    ) {}

    /**
     * The type the relation reaches; a hop of a `->through()` path is monomorphic.
     */
    public function relatedType(): string
    {
        return $this->relation->relatedTypes()[0] ?? '';
    }

    /**
     * The member of the parent row that holds the related row or rows.
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
        return new self($this->relation, $parentType, $this->path);
    }
}
