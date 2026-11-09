<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A family of query keys matched by prefix rather than declared one by one: the family
 * keyed `value` answers `value.cap` and `value.end_date`, where the part after `value.`
 * is data. {@see \haddowg\JsonApi\Resource\Sort\SortFamilyInterface} and
 * {@see \haddowg\JsonApi\Resource\Filter\FilterFamilyInterface} narrow it to sorts and
 * filters, and both match through the same rule: an exact key first, then the family
 * with the longest prefix whose {@see suffixPattern()} accepts the rest of the key.
 */
interface KeyFamilyInterface
{
    /**
     * The prefix, matched as `<prefix>.<suffix>`.
     */
    public function key(): string;

    /**
     * The regular-expression fragment a suffix must match whole, used to reject a key
     * before {@see resolve()} runs and projected into the OpenAPI document. Keep it to
     * the syntax PCRE and ECMA-262 share, since both read it.
     */
    public function suffixPattern(): string;

    /**
     * The concrete member for one suffix, keyed by the full requested key, or `null`
     * when the suffix names nothing, which the client sees as an unrecognized key.
     */
    public function resolve(string $suffix, JsonApiRequestInterface $request): ?object;
}
