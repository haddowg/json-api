<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\DeclaresActionDocumentMetaInterface;
use haddowg\JsonApi\Resource\DocumentMetaMisdeclared;

/**
 * Validation for a custom action's declared success-response set
 * ({@see ActionMetadataInterface::responds()}), owned by core so every integration
 * checks a declared `responds` list identically.
 *
 * The set must be non-empty and hold only {@see ActionResponse} carriers. Each status
 * carries at most one JSON:API response, which bounds it to at most one `200` (an
 * {@see ActionResource} or a {@see MetaResult}), at most one `201` ({@see ActionCreated})
 * and at most one `202` ({@see Accepted}, the sole job-bearing response). {@see RawResult}s sit beside those, any number per
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
     * The status an action's {@see \haddowg\JsonApi\Response\DataResponse} renders with
     * when its handler sets none: `201` when the action declares an {@see ActionCreated}
     * and no {@see ActionResource}, so the created document is the only resource document
     * it can answer with; `null` otherwise, leaving the response's own default (`200`).
     * An action declaring both answers `200` unless its handler calls `withStatus(201)`.
     *
     * @param list<OperationResponseInterface> $responds
     */
    public static function dataStatus(array $responds): ?int
    {
        $created = false;
        foreach ($responds as $response) {
            if ($response instanceof ActionResource) {
                return null;
            }
            $created = $created || $response instanceof ActionCreated;
        }

        return $created ? 201 : null;
    }

    /**
     * The action's declared responses with the members its handler declares for each
     * status ({@see DeclaresActionDocumentMetaInterface::documentMeta()}) as the meta of its
     * {@see ActionResource}, {@see ActionCreated} or {@see MetaResult}. A response that
     * already declares its own members keeps them; a handler declaring members for it too
     * is refused.
     *
     * @param list<ActionResponse> $responds
     *
     * @return ($responds is non-empty-list ? non-empty-list<ActionResponse> : list<ActionResponse>)
     *
     * @throws DocumentMetaMisdeclared when a response and its handler both declare its members
     */
    public static function withDocumentMeta(array $responds, DeclaresActionDocumentMetaInterface $handler): array
    {
        $out = [];
        foreach ($responds as $response) {
            if ($response instanceof ActionResource || $response instanceof ActionCreated || $response instanceof MetaResult) {
                $declared = \array_values([...$handler->documentMeta($response->status())]);
                if ($declared !== [] && $response->meta !== []) {
                    throw new DocumentMetaMisdeclared(\sprintf(
                        'The %d response of the action handled by %s declares its meta on the response and in documentMeta(); declare it in one place.',
                        $response->status(),
                        $handler::class,
                    ));
                }
                if ($declared !== []) {
                    $response = $response->withMeta($declared);
                }
            }
            $out[] = $response;
        }

        return $out;
    }

    /**
     * The {@see SeeOther} an action declares with a media type, or `null`. Declaring the
     * media type opts the action in to answering with a URL: the `200` location document
     * when the request prefers JSON:API, the `303` otherwise
     * ({@see \haddowg\JsonApi\Negotiation\AcceptNegotiator::negotiateAction()}). A bare
     * `SeeOther` stays the plain `303`.
     *
     * @param list<OperationResponseInterface> $responds
     */
    public static function location(array $responds): ?SeeOther
    {
        foreach ($responds as $response) {
            if ($response instanceof SeeOther && $response->mediaType !== null) {
                return $response;
            }
        }

        return null;
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
