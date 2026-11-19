<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

/**
 * The "Field Selection" profile: lets a `fields[TYPE]` parameter select single keys of an
 * object attribute as `<attribute>.<key>`, where JSON:API 1.1 lists only field names.
 *
 * A client never has to ask for it. A server that recognizes it applies it to every
 * response whose request narrows an attribute this way, and advertises the URI on the
 * response `Content-Type` `profile` parameter and in `jsonapi.profile`. A request that
 * narrows nothing gets an unchanged response. The profile reserves no query parameter,
 * since it widens the members `fields[TYPE]` accepts.
 *
 * @see https://jsonapi.org/format/1.1/#profiles
 * @see https://jsonapi.org/format/1.1/#fetching-sparse-fieldsets
 */
final class FieldSelectionProfile extends AbstractProfile
{
    public const string URI = 'https://haddowg.github.io/json-api/profiles/field-selection/';

    public function uri(): string
    {
        return self::URI;
    }
}
