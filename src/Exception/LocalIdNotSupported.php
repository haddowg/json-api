<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A regular (non-atomic) request body carried a local id (`lid`). The `lid` member
 * is defined only by the Atomic Operations extension — it names a resource created
 * earlier in the same atomic batch — so it is meaningless in a standalone request
 * and is rejected (a `400`) rather than silently ignored. This covers a `lid` on the
 * primary resource object, on any embedded relationship linkage, and on a
 * relationship-endpoint linkage body.
 *
 * The `source.pointer` locates the offending `lid`; within an atomic batch the local
 * ids are resolved to real ids before the write handler reads the body, so this is
 * never raised there.
 */
final class LocalIdNotSupported extends AbstractJsonApiException
{
    public function __construct(public readonly string $pointer)
    {
        parent::__construct(
            'A local id (lid) is only supported within an Atomic Operations request; '
            . 'a standalone request must identify a resource by id.',
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'LOCAL_ID_NOT_SUPPORTED',
                title: 'Local id is not supported',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer($this->pointer),
            ),
        ];
    }
}
