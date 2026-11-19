<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;

/**
 * The direction a resource object's `meta` is declared for, as a type's
 * {@see \haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface::resourceMeta()} receives
 * it: a read, which every rendered resource object of the type shares, wherever it
 * appears, or the `data.meta` of a create or an update, and whether that write is an
 * operation inside an Atomic Operations batch.
 *
 * A read has one declaration because one `<Type>Resource` component describes the object
 * in every document. A member's `readOnly()` and `writeOnly()` already split one list
 * between the directions, so most declarations ignore the context.
 *
 * It carries no request. The OpenAPI projection asks for each direction's declaration with
 * none, so a declaration may depend only on what this context says.
 */
final readonly class MetaContext
{
    /**
     * The request attribute an adapter sets on the request of each operation it runs inside
     * an Atomic Operations batch, so what reads the request, such as a resource hydrating
     * `data.meta`, takes the atomic declaration.
     */
    public const string ATOMIC_ATTRIBUTE = 'haddowg.json-api.atomic-operation';

    private function __construct(
        public ?OperationType $operation,
        public bool $atomic,
    ) {}

    /**
     * Every rendered resource object of the type.
     */
    public static function read(): self
    {
        return new self(null, false);
    }

    /**
     * The `data.meta` of a create or an update.
     */
    public static function write(OperationType $operation, bool $atomic = false): self
    {
        if ($operation !== OperationType::Create && $operation !== OperationType::Update) {
            throw new \InvalidArgumentException(\sprintf('A resource object is written by a create or an update; %s is not one.', $operation->value));
        }

        return new self($operation, $atomic);
    }

    /**
     * Whether `$request` is the request of an operation inside an Atomic Operations batch,
     * which its adapter marks with {@see ATOMIC_ATTRIBUTE}.
     */
    public static function inAtomicOperation(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::ATOMIC_ATTRIBUTE) === true;
    }

    public function isRead(): bool
    {
        return $this->operation === null;
    }

    public function isWrite(): bool
    {
        return $this->operation !== null;
    }

    /**
     * Whether this is the write `$operation`.
     */
    public function is(OperationType $operation): bool
    {
        return $this->operation === $operation;
    }

    public function isAtomic(): bool
    {
        return $this->atomic;
    }

    /**
     * Whether a write creates the resource. A read answers `false`.
     */
    public function creating(): bool
    {
        return $this->operation === OperationType::Create;
    }
}
