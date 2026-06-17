<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

/**
 * The "Relationship Counts" profile: lets a client ask for the size of a
 * relationship's set alongside the primary resource, addressing the relationships
 * to count by name through the `withCount` query parameter and reading the result
 * from a `total` member on each named relationship object's `meta`.
 *
 * Reserves the implementation-specific query-parameter family `withCount` — its
 * base carries an uppercase letter, so it satisfies the spec's "at least one
 * non a-z character" rule for a custom query-parameter family. The form is the
 * flat, comma-separated list `?withCount=rel1,rel2` (the same shape as
 * `?include`); each named relationship that the server has opted into counting
 * carries `meta.total` on its relationship object when the resource is rendered.
 *
 * The profile reserves the `total` member it writes into a relationship object's
 * `meta`. (That member is not a query-parameter keyword, so it is not listed in
 * {@see keywords()}, which feeds the strict query-parameter recognized set.)
 *
 * The profile is opt-in: the `withCount` family is parsed only when the client
 * negotiated this URI in the `Accept` `profile` media-type parameter, and is
 * otherwise ignored. A server that recognizes the profile applies it and
 * advertises the URI on the response `Content-Type` `profile` parameter and in
 * `jsonapi.profile` (the existing profile infrastructure).
 *
 * @see https://jsonapi.org/format/1.1/#profiles
 * @see https://jsonapi.org/format/1.1/#query-parameters-custom
 */
final class RelationshipCountsProfile extends AbstractProfile
{
    public const string URI = 'https://haddowg.github.io/json-api/profiles/relationship-counts/';

    /**
     * The query-parameter family base.
     */
    public const string FAMILY = 'withCount';

    /**
     * The relationship-object `meta` member the profile writes the count into.
     */
    public const string META_MEMBER = 'total';

    public function uri(): string
    {
        return self::URI;
    }

    public function keywords(): array
    {
        return [self::FAMILY];
    }
}
