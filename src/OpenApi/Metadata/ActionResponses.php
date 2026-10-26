<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Validation for a custom action's declared success-response set
 * ({@see ActionMetadataInterface::responds()}), owned by core so every integration
 * checks a declared `responds` list identically.
 *
 * The set must be non-empty and hold only {@see ActionResponse} carriers. Each status
 * carries at most one JSON:API response, which bounds it to at most one `200` (an
 * {@see ActionResource} or a {@see MetaResult}) and at most one `202` ({@see Accepted},
 * the sole job-bearing response). {@see RawResult}s sit beside those, any number per
 * status, as long as no two at one status share a media type.
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
        $rawSeen = [];
        foreach ($responds as $response) {
            if (!$response instanceof ActionResponse) {
                throw new \InvalidArgumentException(\sprintf(
                    'An action response must implement %s; got %s.',
                    ActionResponse::class,
                    \get_debug_type($response),
                ));
            }

            $status = $response->status();
            if ($response instanceof RawResult) {
                $key = $status . ' ' . $response->essence();
                if (isset($rawSeen[$key])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'The media type "%s" is declared more than once for status %d of the action; each raw response needs its own media type.',
                        $response->essence(),
                        $status,
                    ));
                }
                $rawSeen[$key] = true;

                continue;
            }

            if (isset($seen[$status])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Status %d is declared more than once for the action; each status may appear at most once (so at most one 200 and one 202).',
                    $status,
                ));
            }
            $seen[$status] = true;
        }

    }

    /**
     * The media types an action can answer with, for
     * {@see \haddowg\JsonApi\Negotiation\AcceptNegotiator}: `application/vnd.api+json`
     * first when the action declares any JSON:API response, then each {@see RawResult}
     * media type once, in declaration order.
     *
     * @param list<OperationResponseInterface> $responds
     *
     * @return list<string>
     */
    public static function mediaTypes(array $responds): array
    {
        $jsonApi = [];
        $raw = [];
        foreach ($responds as $response) {
            if ($response instanceof RawResult) {
                $raw[$response->essence()] = true;
            } else {
                $jsonApi = ['application/vnd.api+json'];
            }
        }

        return [...$jsonApi, ...\array_keys($raw)];
    }
}
