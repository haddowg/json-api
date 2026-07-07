<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Validation for a custom action's declared success-response set
 * ({@see ActionMetadataInterface::responds()}), owned by core so every integration
 * checks a declared `responds` list identically.
 *
 * The set must be non-empty, hold only {@see ActionResponse} carriers, and carry no
 * duplicate status code — which in turn bounds it to at most one `200` (an
 * {@see ActionResource} or a {@see MetaResult}) and at most one `202`
 * ({@see Accepted}, the sole job-bearing response).
 */
final class ActionResponses
{
    /**
     * @param list<OperationResponseInterface> $responds
     */
    public static function validate(array $responds): void
    {
        if ($responds === []) {
            throw new \InvalidArgumentException('An action declares an empty response set; omit the override to default to 204 No Content.');
        }

        $seen = [];
        foreach ($responds as $response) {
            if (!$response instanceof ActionResponse) {
                throw new \InvalidArgumentException(\sprintf(
                    'An action response must implement %s; got %s.',
                    ActionResponse::class,
                    \get_debug_type($response),
                ));
            }

            $status = $response->status();
            if (isset($seen[$status])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Status %d is declared more than once for the action; each status may appear at most once (so at most one 200 and one 202).',
                    $status,
                ));
            }
            $seen[$status] = true;
        }
    }
}
