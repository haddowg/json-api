<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A structural failure parsing an Atomic Operations request document: the document
 * is not an object, carries no `atomic:operations` array, or one operation is
 * malformed (a missing/unknown `op`, neither or both of `ref`/`href`, a structurally
 * invalid `ref`, or a `data` shape inappropriate for the operation).
 *
 * The {@see Error} carries a `source.pointer` locating the offending member —
 * `/atomic:operations` for a document-level failure, `/atomic:operations/<index>`
 * for an operation-level one, or a deeper pointer such as
 * `/atomic:operations/<index>/ref` for a ref-level one. This is a structural
 * (`400`) error: semantic validation (type existence, relationship existence,
 * `lid` resolution) is the executor's concern, not the parser's.
 *
 * @see https://jsonapi.org/ext/atomic/
 */
final class AtomicOperationsInvalid extends AbstractJsonApiException
{
    public function __construct(private readonly string $detail, private readonly string $pointer)
    {
        parent::__construct($detail, 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'ATOMIC_OPERATIONS_INVALID',
                title: 'Atomic operations request is invalid',
                detail: $this->detail,
                source: ErrorSource::fromPointer($this->pointer),
            ),
        ];
    }
}
