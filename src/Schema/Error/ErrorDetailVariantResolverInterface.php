<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Error;

/**
 * An {@see ErrorMessageResolverInterface} that also resolves a more specific `detail`
 * for an error carrying a {@see Error::$detailVariant}, such as
 * `ATTRIBUTE_VALUE_INVALID.date_time` for a date-time a write got wrong. The render layer
 * asks this first, then the code's own `detail()`, then keeps the error's default, and
 * fills the template's `{placeholder}` tokens from the error's context either way.
 *
 * A resolver implementing only {@see ErrorMessageResolverInterface} works as before: a
 * variant it cannot resolve falls back to the code's `detail()`.
 */
interface ErrorDetailVariantResolverInterface extends ErrorMessageResolverInterface
{
    /**
     * A replacement `detail` template for `$variant`, or `null` to fall back to the code's.
     */
    public function detailVariant(string $variant): ?string;
}
