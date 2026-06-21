<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

/**
 * A local id (`lid`) was registered twice for the same `type` within one atomic
 * request: an operation tried to claim a `(type, lid)` pair the {@see \haddowg\JsonApi\Atomic\LocalIdRegistry}
 * already holds.
 *
 * The error carries no `source.pointer`: the {@see \haddowg\JsonApi\Atomic\AtomicLoop}
 * (and the bundle executor) decorate it with the failing operation's pointer, so
 * the registry — which has no notion of operation index — must not pre-set one.
 */
final class LocalIdConflict extends AbstractJsonApiException
{
    public function __construct(public readonly string $type, public readonly string $lid)
    {
        parent::__construct("Local id '$lid' is already registered for type '$type'!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'LOCAL_ID_CONFLICT',
                title: 'Local id conflict',
                detail: $this->getMessage(),
            ),
        ];
    }
}
