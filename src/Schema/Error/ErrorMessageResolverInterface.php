<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Error;

/**
 * Resolves a (possibly localized) replacement message template for an error,
 * keyed by its stable {@see Error::$code}. The render layer consults it once per
 * error and interpolates the error's {@see Error::$context} into whatever template
 * comes back, so an integration binds only a thin adapter over its framework
 * translator — locale negotiation stays the framework's job, not core's.
 *
 * Returning `null` from either method keeps the error's own default copy, so a
 * partial translation (only some codes, or only titles) degrades gracefully per
 * slot. The seam exposes only the human-readable copy: an error's `code` and
 * `status` are the machine and HTTP contract and are never resolvable here.
 */
interface ErrorMessageResolverInterface
{
    /**
     * A replacement `title` template for `$code` — it may contain `{placeholder}`
     * tokens, which the render layer fills from the error's context — or `null` to
     * keep the error's default title.
     */
    public function title(string $code): ?string;

    /**
     * A replacement `detail` template for `$code` — it may contain `{placeholder}`
     * tokens, which the render layer fills from the error's context — or `null` to
     * keep the error's default detail.
     */
    public function detail(string $code): ?string;
}
