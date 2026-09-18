<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * A local id (`lid`) was registered twice for the same `type` within one atomic
 * request: an operation tried to claim a `(type, lid)` pair the {@see \haddowg\JsonApi\Atomic\LocalIdRegistry}
 * already holds.
 *
 * The error carries no `source.pointer`: the {@see \haddowg\JsonApi\Atomic\AtomicLoop}
 * (and the bundle executor) decorate it with the failing operation's pointer, so
 * the registry — which has no notion of operation index — must not pre-set one.
 */
final class LocalIdConflict extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type, public readonly string $lid)
    {
        parent::__construct("Local id '$lid' is already registered for type '$type'!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'LOCAL_ID_CONFLICT',
            status: 400,
            title: 'Local id conflict',
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
