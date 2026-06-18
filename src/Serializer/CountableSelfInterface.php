<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * An opt-in capability a {@see SerializerInterface} MAY implement to declare its
 * **primary collection** countable — eligible for the reserved `?withCount=_self_`
 * token, which exposes the collection's total as `meta.total` (and, when paginated,
 * `meta.page.total` + the `last` link). The resource document reads it via
 * `instanceof CountableSelfInterface` to validate a requested `_self_` up front:
 * a `?withCount=_self_` against a serializer that is not this interface — or whose
 * {@see isCountable()} returns `false` — is rejected with
 * {@see \haddowg\JsonApi\Exception\RelationshipCountNotAllowed} (400). Mirrors the
 * relation-level gate {@see CountableControlsInterface} for the primary `_self_`.
 *
 * Not part of {@see SerializerInterface}: a bare serializer (no resource) does not
 * implement it, so `?withCount=_self_` against it is rejected — the safe default,
 * counting is opt-in. {@see \haddowg\JsonApi\Resource\AbstractResource} implements
 * it via its {@see \haddowg\JsonApi\Resource\AbstractResource::countable()} flag.
 */
interface CountableSelfInterface
{
    /**
     * Whether the primary collection is countable via `?withCount=_self_`. Return
     * `false` to forbid the `_self_` count on this serializer.
     */
    public function isCountable(): bool;
}
