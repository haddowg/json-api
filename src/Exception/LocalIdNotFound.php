<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * An operation referenced a local id (`lid`) for a `type` that the
 * {@see \haddowg\JsonApi\Atomic\LocalIdRegistry} has not yet seen: the referenced
 * resource was never assigned that `lid` by an earlier operation in the batch.
 *
 * The error carries no `source.pointer`: the {@see \haddowg\JsonApi\Atomic\AtomicLoop}
 * (and the bundle executor) decorate it with the failing operation's pointer, so
 * the registry — which has no notion of operation index — must not pre-set one.
 */
final class LocalIdNotFound extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type, public readonly string $lid)
    {
        parent::__construct("No resource is registered for local id '$lid' of type '$type'!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'LOCAL_ID_NOT_FOUND',
            status: 400,
            title: 'Local id not found',
            context: ['lid' => ErrorContextType::Str, 'type' => ErrorContextType::Str],
            feature: ErrorFeature::AtomicOperations,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['lid' => $this->lid, 'type' => $this->type],
            ),
        ];
    }
}
