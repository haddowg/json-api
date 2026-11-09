<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\KeyFamilyInterface;

/**
 * A family of filters matched by key prefix rather than an exact key: the family keyed
 * `value` answers `filter[value.cap]` and `filter[value.end_date]`, where the part after
 * `value.` is data (a custom field, an EAV attribute, a JSON member).
 *
 * {@see key()} is the prefix. A family never matches the bare prefix, only
 * `<prefix>.<suffix>` with a non-empty suffix that {@see suffixPattern()} accepts. It is
 * never handed to a filter handler: {@see FilterMatcher} resolves it to the concrete
 * {@see FilterInterface} for one requested key, and that filter is what a
 * {@see FilterDirective} carries.
 */
interface FilterFamilyInterface extends FilterInterface, KeyFamilyInterface
{
    /**
     * Any non-empty suffix.
     */
    public const string DEFAULT_SUFFIX_PATTERN = '.+';

    public function suffixPattern(): string;

    /**
     * The concrete filter for one suffix (`cap` for `filter[value.cap]`), or `null` when
     * the suffix names nothing this family filters by, which the client sees as an
     * unrecognized filter. The returned filter's {@see FilterInterface::key()} must be
     * the full requested key, `<prefix>.<suffix>`, and it must declare the same
     * {@see ListValue} the family does.
     */
    public function resolve(string $suffix, JsonApiRequestInterface $request): ?FilterInterface;
}
