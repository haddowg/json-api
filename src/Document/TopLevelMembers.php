<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Document;

/**
 * The canonical order of a JSON:API document's top-level members.
 *
 * JSON:API does not mandate member order, but every serialized response is
 * normalised to this order — `data` (or, in its place, `errors`) first and
 * `jsonapi` last — so a document's shape is identical regardless of how it was
 * assembled. The generated OpenAPI envelope schemas mirror the same order, so the
 * rendered documentation matches the wire.
 */
final class TopLevelMembers
{
    /**
     * @var list<string>
     */
    public const ORDER = ['data', 'errors', 'included', 'links', 'meta', 'jsonapi'];
}
