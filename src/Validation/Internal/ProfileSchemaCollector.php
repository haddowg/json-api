<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Validation\SchemaContributingProfile;

/**
 * Collects the JSON Schema fragments of the {@see SchemaContributingProfile}s in
 * scope for a request, for the validation middleware to compose with the base
 * schema.
 *
 * "In scope" mirrors {@see \haddowg\JsonApi\Response\AbstractResponse::appliedProfiles()}:
 * the profiles a request requested or required (via the `Accept`/`Content-Type`
 * `profile` parameter or the `profile` query parameter) that the server actually
 * registers. A registered profile the request did not ask for does not augment
 * validation.
 *
 * @internal
 */
final class ProfileSchemaCollector
{
    /**
     * @return list<object> the decoded schema fragments, de-duplicated by profile URI
     */
    public static function collect(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        $fragments = [];
        $seen = [];

        // Accept-based negotiation only (final JSON:API 1.1): the RC-era `?profile=` query
        // parameter no longer contributes a validation schema, matching the Accept-only gates.
        foreach ($request->getRequestedProfiles() as $uri) {
            if (isset($seen[$uri])) {
                continue;
            }
            $seen[$uri] = true;

            $profile = $server->profiles()->get($uri);
            if ($profile instanceof \haddowg\JsonApi\Validation\SchemaContributingProfileInterface) {
                $fragment = $profile->schemaFragment();
                if ($fragment !== null) {
                    $fragments[] = $fragment;
                }
            }
        }

        return $fragments;
    }
}
