<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

/**
 * The "Relationship Queries" profile: lets a client filter and sort a
 * relationship's linkage from the primary request, addressing the relationship
 * by its include path.
 *
 * Reserves the implementation-specific query-parameter families `relatedQuery`
 * (canonical) and `rQ` (shorthand alias) — each carries an uppercase letter, so
 * both satisfy the spec's "at least one non a-z character" rule for a custom
 * query-parameter family. The form is
 * `relatedQuery[<relationship-path>][sort]=-field,field` and
 * `relatedQuery[<relationship-path>][filter][<key>]=<value>` (the `rQ` alias is
 * identical; on a conflict the canonical `relatedQuery` wins). The path is a
 * relationship/include path — dotted paths (e.g. `albums.tracks`) are legal in
 * the single bracket per the family grammar.
 *
 * The profile is opt-in: the `relatedQuery` / `rQ` families are parsed only when
 * the client negotiated this URI in the `Accept` `profile` media-type parameter,
 * and are otherwise ignored. A server that recognizes the profile applies it and
 * advertises the URI on the response `Content-Type` `profile` parameter and in
 * `jsonapi.profile` (the existing profile infrastructure). `page` is deliberately
 * not part of this profile: an addressed relationship always renders page 1 from
 * the primary request and is navigated via its own relationship-object
 * pagination links.
 *
 * @see https://jsonapi.org/format/1.1/#profiles
 * @see https://jsonapi.org/format/1.1/#query-parameters
 */
final class RelationshipQueriesProfile extends AbstractProfile
{
    public const string URI = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    /**
     * The canonical query-parameter family base.
     */
    public const string FAMILY = 'relatedQuery';

    /**
     * The shorthand query-parameter family base. Identical semantics to
     * {@see FAMILY}; on a conflict targeting the same `[path][op]`, the canonical
     * {@see FAMILY} wins.
     */
    public const string FAMILY_SHORTHAND = 'rQ';

    public function uri(): string
    {
        return self::URI;
    }

    public function keywords(): array
    {
        return [self::FAMILY, self::FAMILY_SHORTHAND];
    }
}
