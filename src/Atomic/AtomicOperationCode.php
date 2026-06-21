<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

use haddowg\JsonApi\Exception\AtomicOperationsInvalid;

/**
 * The operation code of one atomic operation: the verb the executor applies.
 *
 * The three codes mirror the extension's `op` member values: `add` creates a
 * resource (or adds members to a to-many relationship), `update` replaces a
 * resource or a relationship, and `remove` deletes a resource (or removes members
 * from a to-many relationship). The distinction between a resource operation and a
 * relationship operation is carried by the operation's {@see Ref} (whether it names
 * a `relationship`), not by the code.
 *
 * @see https://jsonapi.org/ext/atomic/#operation-objects
 */
enum AtomicOperationCode: string
{
    case Add = 'add';

    case Update = 'update';

    case Remove = 'remove';

    /**
     * Resolves the wire `op` value to its code, throwing a typed parse error
     * (carrying the supplied `source.pointer`) for an unknown value.
     *
     * @throws AtomicOperationsInvalid when `$op` is not one of `add`/`update`/`remove`
     */
    public static function fromString(string $op, string $pointer): self
    {
        return self::tryFrom($op)
            ?? throw new AtomicOperationsInvalid("Unknown atomic operation code '$op'.", $pointer);
    }
}
