<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * Optional capability: a serializer that declares whether the by-convention
 * resource-object `self` link (`{baseUri}/{uriType}/{id}`) is emitted. The spec
 * RECOMMENDS a resource carry a `self` link, so the transformer emits one by
 * default — for every serializer, including those that do not implement this
 * interface (they are treated as emitting). A serializer implements it only to
 * opt *out*.
 *
 * Mirrors the {@see UriTypeAwareInterface} / {@see IncludeControlsInterface}
 * capability pattern: an external serializer or a bare serializer/hydrator pair
 * that does not implement it is unaffected and still gets a convention `self`.
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it, defaulting to
 * `true`; override {@see emitsSelfLink()} to return `false` to opt out.
 */
interface SelfLinkAwareInterface
{
    /**
     * Whether this resource emits the by-convention `self` link. `true` (the
     * implicit default for any serializer that does not implement this
     * interface) emits it; `false` opts out.
     */
    public function emitsSelfLink(): bool;
}
