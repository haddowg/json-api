<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A family of sorts matched by key prefix rather than an exact key: the family
 * keyed `value` answers `sort=value.cap` and `sort=-value.end_date`, where the
 * part after `value.` is data (a custom field, an EAV attribute, a JSON member).
 *
 * {@see key()} is the prefix. A family never matches the bare prefix, only
 * `<prefix>.<suffix>` with a non-empty suffix that {@see suffixPattern()} accepts.
 * It is never handed to a sort handler: {@see SortMatcher} resolves it to the
 * concrete {@see SortInterface} for one requested key, and that sort is what a
 * {@see SortDirective} carries.
 */
interface SortFamilyInterface extends SortInterface
{
    /**
     * Any non-empty suffix without a comma, the separator of the `sort` list.
     */
    public const string DEFAULT_SUFFIX_PATTERN = '[^,]+';

    /**
     * The regular-expression fragment a suffix must match whole, used to reject a key
     * before {@see resolve()} runs and projected into the OpenAPI document. Keep it to
     * the syntax PCRE and ECMA-262 share, since both read it.
     */
    public function suffixPattern(): string;

    /**
     * The concrete sort for one suffix (`cap` for `sort=value.cap`), or `null` when
     * the suffix names nothing this family sorts by, which the client sees as an
     * unrecognized sort key. The returned sort's {@see SortInterface::key()} must be
     * the full requested key, `<prefix>.<suffix>`.
     */
    public function resolve(string $suffix, JsonApiRequestInterface $request): ?SortInterface;
}
