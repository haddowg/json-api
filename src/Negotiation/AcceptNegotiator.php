<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Request\MediaType;

/**
 * Picks the media type a response is rendered in from the ones an endpoint can produce,
 * such as an action that answers with a JSON:API document or a CSV export
 * ({@see \haddowg\JsonApi\OpenApi\Metadata\ActionResponses::mediaTypes()}).
 *
 * Each available media type takes the `q` weight of the most specific `Accept` range
 * that matches it (`text/csv` before `text/*` before `*\/*`), and the highest weight
 * wins. On a tie `application/vnd.api+json` wins, then the earlier entry, so JSON:API
 * is the answer whenever the client accepts it as readily as anything else. An absent
 * `Accept` accepts everything. A JSON:API range carrying a parameter other than `ext`
 * or `profile` matches nothing, as the spec has a server ignore it.
 *
 * Negotiation only picks the success body. An error is always a JSON:API document,
 * whatever was picked.
 */
final class AcceptNegotiator
{
    private const string JSON_API = 'application/vnd.api+json';

    /**
     * The best available media type for the `Accept` header, or `null` when the client
     * accepts none of them.
     *
     * @param list<string> $available the media types the endpoint can produce; parameters are ignored
     */
    public static function best(string $accept, array $available): ?string
    {
        $ranges = self::ranges($accept);

        $best = null;
        $bestQ = 0.0;
        $bestIsJsonApi = false;
        foreach ($available as $candidate) {
            $mediaType = MediaType::essence($candidate);
            $q = self::weight($mediaType, $ranges);
            if ($q <= 0.0) {
                continue;
            }

            $isJsonApi = $mediaType === self::JSON_API;
            if ($best === null || $q > $bestQ || ($q === $bestQ && $isJsonApi && !$bestIsJsonApi)) {
                $best = $mediaType;
                $bestQ = $q;
                $bestIsJsonApi = $isJsonApi;
            }
        }

        return $best;
    }

    /**
     * The best available media type for the `Accept` header.
     *
     * @param list<string> $available the media types the endpoint can produce; parameters are ignored
     *
     * @throws MediaTypeUnacceptable when the client accepts none of them (`406`)
     */
    public static function negotiate(string $accept, array $available): string
    {
        return self::best($accept, $available) ?? throw new MediaTypeUnacceptable($accept);
    }

    /**
     * The media type a custom action answers in, from the responses it declares
     * ({@see ActionResponses::mediaTypes()}). An action that declares a {@see SeeOther}
     * can always answer, with its `303`, so where no declared media type is acceptable it
     * gets `null` rather than a `406`: a browser navigating to a download sends
     * `text/html` and must still be redirected.
     *
     * @param list<OperationResponseInterface> $responds
     *
     * @throws MediaTypeUnacceptable when the client accepts none of them and the action declares no {@see SeeOther} (`406`)
     */
    public static function negotiateAction(string $accept, array $responds): ?string
    {
        $best = self::best($accept, ActionResponses::mediaTypes($responds));
        if ($best === null && ActionResponses::seeOther($responds) === null) {
            throw new MediaTypeUnacceptable($accept);
        }

        return $best;
    }

    /**
     * Whether a custom action's {@see SeeOther} answers with the `200` location document
     * rather than the `303`: only when the `Accept` header names
     * `application/vnd.api+json` itself, and no other range it lists weighs more.
     *
     * A wildcard never counts as asking for JSON:API, so an absent `Accept`, `*\/*`, a
     * browser's navigation `Accept` and a `text/csv` all get the `303`. A JSON:API range
     * carrying a parameter other than `ext` or `profile` counts as absent, as in
     * {@see best()}. On equal weights JSON:API wins, since the client named it.
     */
    public static function prefersJsonApi(string $accept): bool
    {
        $jsonApi = 0.0;
        $other = 0.0;
        foreach (self::ranges($accept) as [$range, , $q]) {
            if ($range === self::JSON_API) {
                $jsonApi = \max($jsonApi, $q);
            } else {
                $other = \max($other, $q);
            }
        }

        return $jsonApi > 0.0 && $jsonApi >= $other;
    }

    /**
     * The weight the most specific matching range gives `$mediaType`, or `0` when no
     * range matches.
     *
     * @param list<array{string, int, float}> $ranges range, specificity and weight
     */
    private static function weight(string $mediaType, array $ranges): float
    {
        [$type] = \explode('/', $mediaType, 2);

        $specificity = 0;
        $q = 0.0;
        foreach ($ranges as [$range, $rangeSpecificity, $rangeQ]) {
            $matches = match ($rangeSpecificity) {
                3 => $range === $mediaType,
                2 => $range === $type . '/*',
                default => true,
            };
            if (!$matches || $rangeSpecificity < $specificity) {
                continue;
            }
            if ($rangeSpecificity > $specificity || $rangeQ > $q) {
                $q = $rangeQ;
            }
            $specificity = $rangeSpecificity;
        }

        return $q;
    }

    /**
     * The usable ranges of an `Accept` header, each with its specificity (3 exact, 2
     * `type/*`, 1 `*\/*`) and `q` weight.
     *
     * @return list<array{string, int, float}>
     */
    private static function ranges(string $accept): array
    {
        if (\trim($accept) === '') {
            return [['*/*', 1, 1.0]];
        }

        $ranges = [];
        foreach (MediaType::split($accept) as $instance) {
            $range = MediaType::essence($instance);
            if (\preg_match('#^([^/\s]+)/([^/\s]+)$#', $range, $parts) !== 1) {
                continue;
            }
            if ($range === self::JSON_API && !MediaType::accepts($instance)) {
                continue;
            }

            $q = 1.0;
            if (\preg_match('/;\s*q\s*=\s*([0-9.]+)/i', $instance, $weight) === 1) {
                $q = \max(0.0, \min(1.0, (float) $weight[1]));
            }

            $specificity = match (true) {
                $parts[1] === '*' && $parts[2] === '*' => 1,
                $parts[2] === '*' => 2,
                default => 3,
            };
            if ($parts[1] === '*' && $specificity !== 1) {
                continue;
            }

            $ranges[] = [$range, $specificity, $q];
        }

        return $ranges;
    }
}
