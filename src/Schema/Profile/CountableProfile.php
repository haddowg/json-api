<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

/**
 * The "Countable" profile: lets a client ask for the size of a countable
 * collection alongside the primary resource — a named relationship's set
 * (`?withCount=comments`) and/or the **primary collection itself** via the
 * reserved `_self_` token (`?withCount=_self_`). The result is read from a `total`
 * member on each named relationship object's `meta` (for a relation) or on the
 * top-level `meta` (for `_self_`).
 *
 * Reserves the implementation-specific query-parameter family `withCount` — its
 * base carries an uppercase letter, so it satisfies the spec's "at least one
 * non a-z character" rule for a custom query-parameter family. The form is the
 * flat, comma-separated list `?withCount=_self_,rel1,rel2` (the same shape as
 * `?include`); each named target that the server has declared countable carries
 * `meta.total` when the resource is rendered.
 *
 * The profile reserves the `total` member it writes into `meta`. (That member is
 * not a query-parameter keyword, so it is not listed in {@see keywords()}, which
 * feeds the strict query-parameter recognized set.)
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
final class CountableProfile extends AbstractProfile
{
    public const string URI = 'https://haddowg.github.io/json-api/profiles/countable/';

    /**
     * The query-parameter family base. (The `?withCount` param name is unchanged by
     * the profile rename — only the profile identity/URI is "Countable".)
     */
    public const string FAMILY = 'withCount';

    /**
     * The reserved `?withCount` token naming the **primary collection** (rather than
     * a relationship): `?withCount=_self_` counts the current request's primary data,
     * gated on that resource/relation being {@see \haddowg\JsonApi\Resource\AbstractResource::countable()}.
     */
    public const string SELF_TOKEN = '_self_';

    /**
     * The `meta` member the profile writes the count into (top-level for `_self_`,
     * on a relationship object for a relation).
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
