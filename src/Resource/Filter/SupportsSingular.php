<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Opt-in capability for a filter that, when the client applies it, guarantees a
 * zero-to-one result — so the collection it filters renders as a single resource
 * object or `null` in `data`, not an array (the JSON:API zero-to-one shape). A
 * filter on a unique attribute (a slug, a UUID) is the canonical case.
 *
 * Mirrors {@see HasDefaultValue}: a filter declares the capability by implementing
 * this interface, and the adapter's collection handler reads {@see isSingular()}
 * for an *applied* filter and collapses the response to a single resource. The
 * flag has no effect on relationship endpoints, and none when the client does not
 * send the filter (the normal zero-to-many collection is returned).
 */
interface SupportsSingular
{
    /**
     * Whether applying this filter yields a zero-to-one (single-resource) response.
     */
    public function isSingular(): bool;
}
