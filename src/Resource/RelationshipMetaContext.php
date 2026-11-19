<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\Mode;

/**
 * The relationship write whose meta-only `200` a type declares the top-level `meta` of, as
 * its {@see \haddowg\JsonApi\Serializer\DeclaresRelationshipDocumentMetaInterface::relationshipDocumentMeta()}
 * receives it: a replace ({@see Mode::Replace}), add or remove on one relationship, and
 * whether the write is an operation inside an Atomic Operations batch.
 *
 * It carries no request. The OpenAPI projection asks for every relationship write's
 * declaration with none, so a declaration may depend only on what this context says.
 */
final readonly class RelationshipMetaContext
{
    public function __construct(
        public string $relation,
        public Mode $mode,
        public bool $atomic = false,
    ) {}

    /**
     * Whether this is a write on the relationship `$relation`, and when `$mode` is given only
     * that mutation.
     */
    public function isRelationship(string $relation, ?Mode $mode = null): bool
    {
        return $this->relation === $relation && ($mode === null || $this->mode === $mode);
    }

    public function is(Mode $mode): bool
    {
        return $this->mode === $mode;
    }

    public function isAtomic(): bool
    {
        return $this->atomic;
    }

    /**
     * A short description for messages, such as `"tags" relationship add`.
     */
    public function describe(): string
    {
        return ($this->atomic ? 'atomic ' : '') . \sprintf('"%s" relationship %s', $this->relation, \strtolower($this->mode->name));
    }
}
