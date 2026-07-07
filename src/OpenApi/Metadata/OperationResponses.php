<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * The per-operation success-response defaults and set validation, owned by core so
 * every integration ({@see TypeMetadataInterface} implementation) resolves and
 * validates a declared response set identically.
 *
 * A type that declares no override for an operation gets {@see defaultFor()} (the
 * single status the operation has always advertised, so the projected document is
 * unchanged). A declared override is checked with {@see validate()} before it reaches
 * the projector.
 */
final class OperationResponses
{
    /**
     * The status codes each operation may legitimately advertise (JSON:API 1.1).
     *
     * @var array<string, list<int>>
     */
    private const VALID = [
        OperationType::FetchCollection->value => [200],
        OperationType::FetchOne->value => [200, 303],
        OperationType::Create->value => [201, 204, 202],
        OperationType::Update->value => [200, 204, 202],
        OperationType::Delete->value => [204, 200],
    ];

    /**
     * The marker interface every declared response for an operation must implement —
     * so a carrier valid for another operation but sharing a status (e.g. {@see Ok},
     * `200`, passed to delete whose `200` is {@see MetaResult}) is rejected.
     *
     * @var array<string, class-string<OperationResponseInterface>>
     */
    private const MARKER = [
        OperationType::FetchCollection->value => FetchCollectionResponse::class,
        OperationType::FetchOne->value => FetchOneResponse::class,
        OperationType::Create->value => CreateResponse::class,
        OperationType::Update->value => UpdateResponse::class,
        OperationType::Delete->value => DeleteResponse::class,
    ];

    /**
     * The default success-response set for an operation — the single status it has
     * always advertised. A type that declares no override projects exactly this.
     *
     * @return non-empty-list<OperationResponseInterface>
     */
    public static function defaultFor(OperationType $operation): array
    {
        return match ($operation) {
            OperationType::FetchCollection => [new Ok()],
            OperationType::FetchOne => [new Ok()],
            OperationType::Create => [new Created()],
            OperationType::Update => [new Ok()],
            OperationType::Delete => [new NoContent()],
        };
    }

    /**
     * Validates a declared override set for an operation: non-empty; every element a
     * carrier for this operation (implements its marker interface); no duplicate status
     * codes; at most one job-bearing (`202`) response; and every status within the
     * operation's spec-valid set. Throws {@see \InvalidArgumentException} otherwise.
     *
     * @param list<OperationResponseInterface> $responses
     */
    public static function validate(OperationType $operation, array $responses): void
    {
        if ($responses === []) {
            throw new \InvalidArgumentException(\sprintf(
                'The %s operation was declared with an empty response set; omit the override to use the default.',
                $operation->value,
            ));
        }

        $marker = self::MARKER[$operation->value];
        $valid = self::VALID[$operation->value];
        $seen = [];
        $jobBearing = 0;
        foreach ($responses as $response) {
            if (!$response instanceof $marker) {
                throw new \InvalidArgumentException(\sprintf(
                    'A %s response is not valid for the %s operation.',
                    $response::class,
                    $operation->value,
                ));
            }
            $status = $response->status();
            if (!\in_array($status, $valid, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Status %d is not valid for the %s operation; allowed: %s.',
                    $status,
                    $operation->value,
                    \implode(', ', $valid),
                ));
            }
            if (isset($seen[$status])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Status %d is declared more than once for the %s operation.',
                    $status,
                    $operation->value,
                ));
            }
            $seen[$status] = true;
            if ($response->jobType() !== null) {
                ++$jobBearing;
            }
        }

        if ($jobBearing > 1) {
            throw new \InvalidArgumentException(\sprintf(
                'The %s operation declares more than one asynchronous (202) response; only one job type is permitted.',
                $operation->value,
            ));
        }
    }
}
