<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Profile\AbstractProfile;

/**
 * The published "Cursor Pagination" profile by Ethan Resnick.
 *
 * Advertises the cursor-pagination profile and declares the `page[size]`,
 * `page[after]` and `page[before]` query parameters this library reads. The
 * profile's full member vocabulary — including its `meta.page` members — is
 * defined by the external specification (the authority) the URI points to.
 * {@see CursorBasedPage} activates it so cursor-paginated responses advertise
 * the profile URI on the `Content-Type` and in `jsonapi.profile`.
 *
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
 */
final class CursorPaginationProfile extends AbstractProfile
{
    public const string URI = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/';

    public function uri(): string
    {
        return self::URI;
    }

    public function keywords(): array
    {
        return ['page[size]', 'page[after]', 'page[before]'];
    }
}
