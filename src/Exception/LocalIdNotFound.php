<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

/**
 * An operation referenced a local id (`lid`) for a `type` that the
 * {@see \haddowg\JsonApi\Atomic\LocalIdRegistry} has not yet seen: the referenced
 * resource was never assigned that `lid` by an earlier operation in the batch.
 *
 * The error carries no `source.pointer`: the {@see \haddowg\JsonApi\Atomic\AtomicLoop}
 * (and the bundle executor) decorate it with the failing operation's pointer, so
 * the registry — which has no notion of operation index — must not pre-set one.
 */
final class LocalIdNotFound extends AbstractJsonApiException
{
    public function __construct(public readonly string $type, public readonly string $lid)
    {
        parent::__construct("No resource is registered for local id '$lid' of type '$type'!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'LOCAL_ID_NOT_FOUND',
                title: 'Local id not found',
                detail: $this->getMessage(),
            ),
        ];
    }
}
