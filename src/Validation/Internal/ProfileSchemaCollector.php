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

        foreach ([...$request->getRequestedProfiles(), ...$request->getRequiredProfiles()] as $uri) {
            if (isset($seen[$uri])) {
                continue;
            }
            $seen[$uri] = true;

            $profile = $server->profiles()->get($uri);
            if ($profile instanceof SchemaContributingProfile) {
                $fragment = $profile->schemaFragment();
                if ($fragment !== null) {
                    $fragments[] = $fragment;
                }
            }
        }

        return $fragments;
    }
}
