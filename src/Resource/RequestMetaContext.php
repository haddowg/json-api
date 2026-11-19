<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\Field\Mode;

/**
 * The write a request document's top-level `meta` is declared for, as a type's
 * {@see \haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface::requestDocumentMeta()}
 * receives it: a create, an update or a delete of the resource, or a replace, add or
 * remove on one of its relationships, and whether the write is an operation inside an
 * Atomic Operations batch.
 *
 * It carries no request. The OpenAPI projection asks for every write's declaration with
 * none, so a declaration may depend only on what this context says.
 */
final readonly class RequestMetaContext
{
    private function __construct(
        public ?OperationType $operation,
        public ?string $relation,
        public ?Mode $mode,
        public bool $atomic,
    ) {}

    /**
     * A create, update or delete of the resource.
     */
    public static function forOperation(OperationType $operation, bool $atomic = false): self
    {
        if (!\in_array($operation, [OperationType::Create, OperationType::Update, OperationType::Delete], true)) {
            throw new \InvalidArgumentException(\sprintf('A request document declares its meta for a write; %s is not one.', $operation->value));
        }

        return new self($operation, null, null, $atomic);
    }

    /**
     * A replace ({@see Mode::Replace}), add or remove on the relationship `$relation`.
     */
    public static function forRelationship(string $relation, Mode $mode, bool $atomic = false): self
    {
        return new self(null, $relation, $mode, $atomic);
    }

    /**
     * Whether this is the resource write `$operation`.
     */
    public function is(OperationType $operation): bool
    {
        return $this->operation === $operation;
    }

    /**
     * Whether this is a write on a relationship: any, or the one named `$relation`, and
     * when `$mode` is given only that mutation.
     */
    public function isRelationship(?string $relation = null, ?Mode $mode = null): bool
    {
        return $this->relation !== null
            && ($relation === null || $this->relation === $relation)
            && ($mode === null || $this->mode === $mode);
    }

    /**
     * Whether the write is one operation of an Atomic Operations batch, whose own `meta`
     * this declaration types.
     */
    public function isAtomic(): bool
    {
        return $this->atomic;
    }

    /**
     * A short description for messages, such as `Create` or `the "tags" relationship add`.
     */
    public function describe(): string
    {
        $what = $this->operation !== null
            ? $this->operation->value
            : \sprintf('"%s" relationship %s', (string) $this->relation, \strtolower(($this->mode ?? Mode::Replace)->name));

        return $this->atomic ? 'atomic ' . $what : $what;
    }
}
