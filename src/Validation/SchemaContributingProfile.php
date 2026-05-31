<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

use haddowg\JsonApi\Schema\Profile\ProfileInterface;

/**
 * Opt-in extension of {@see ProfileInterface} for profiles that contribute a
 * JSON Schema fragment to document validation.
 *
 * When such a profile is in scope for a request (server-registered and
 * requested/required by the request), the {@see DocumentValidator} composes its
 * fragment with the base schema via `allOf`. Because the composite owns the
 * top-level `unevaluatedProperties`, a fragment may both **add** constraints
 * (e.g. require a profile-reserved `meta` member to have a given shape) and
 * **permit** profile-reserved top-level members the base schema would otherwise
 * reject (by declaring them under the fragment's top-level `properties`).
 *
 * Profiles that contribute nothing simply do not implement this interface (or
 * return `null`); the base validation is then unchanged.
 *
 * @see https://jsonapi.org/format/1.1/#profiles
 */
interface SchemaContributingProfile extends ProfileInterface
{
    /**
     * A decoded JSON Schema (draft 2020-12) fragment that augments validation
     * while this profile is in scope, or `null` to contribute nothing.
     */
    public function schemaFragment(): ?object;
}
