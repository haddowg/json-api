<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Internal\KeyFamilyMatcher;
use haddowg\JsonApi\Resource\KeyFamilyInterface;

/**
 * Matches a requested `filter[<key>]` against a declared filter vocabulary, by the rule
 * {@see \haddowg\JsonApi\Resource\Sort\SortMatcher} applies to sort keys: an exact
 * {@see FilterInterface::key()} first, then the {@see FilterFamilyInterface} with the
 * longest prefix whose suffix pattern accepts the rest of the key.
 *
 * A family never matches its bare prefix, and the most specific family decides: when its
 * resolver returns `null` the key is unrecognized, it does not fall through to a shorter
 * prefix. Every data layer resolves filters through here, so a key means the same thing
 * on every path.
 */
final class FilterMatcher
{
    /**
     * The filter `$key` selects from `$filters`, or `null` when nothing declared answers it.
     *
     * @param list<FilterInterface> $filters
     *
     * @throws \LogicException when a family must resolve the key and no request was given, or a family resolves a filter whose key is not `$key` or whose list differs from the family's
     */
    public static function match(array $filters, string $key, ?JsonApiRequestInterface $request = null): ?FilterInterface
    {
        return KeyFamilyMatcher::match(
            $filters,
            $key,
            $request,
            static fn(FilterInterface $filter): string => $filter->key(),
            'filter',
            self::verifyList(...),
        );
    }

    /**
     * The {@see FilterDirective}s for a requested `filter` map, in request order, each
     * value read as a list when its filter {@see AcceptsList accepts one}.
     *
     * @param array<array-key, mixed> $requested the `filter[…]` map, with any defaults already folded in
     * @param list<FilterInterface>   $filters   the filter vocabulary declared for the endpoint
     *
     * @return list<FilterDirective>
     *
     * @throws FilterParamUnrecognized when a requested key matches nothing declared
     * @throws FilterValueInvalid      when a list filter's value is not a list it accepts
     */
    public static function directives(array $requested, array $filters, ?JsonApiRequestInterface $request = null): array
    {
        $directives = [];
        foreach ($requested as $key => $value) {
            $key = (string) $key;
            $filter = self::match($filters, $key, $request)
                ?? throw new FilterParamUnrecognized($key);

            $list = $filter instanceof AcceptsList ? $filter->listValue() : null;
            $directives[] = new FilterDirective($filter, $list === null ? $value : $list->values($key, $value));
        }

        return $directives;
    }

    /**
     * Merges filter vocabularies, a later filter replacing an earlier one with the same
     * key. An exact filter and a family sharing a key are different entries, since a
     * family never answers its bare prefix.
     *
     * @param list<FilterInterface> ...$vocabularies
     *
     * @return list<FilterInterface>
     */
    public static function merge(array ...$vocabularies): array
    {
        return KeyFamilyMatcher::merge(static fn(FilterInterface $filter): string => $filter->key(), \array_values($vocabularies));
    }

    /**
     * @throws \LogicException when the resolved filter's list differs from the family's
     */
    private static function verifyList(KeyFamilyInterface $family, FilterInterface $filter): void
    {
        $declared = $family instanceof AcceptsList ? $family->listValue() : null;
        $resolved = $filter instanceof AcceptsList ? $filter->listValue() : null;

        if ($declared?->delimiter !== $resolved?->delimiter || ($declared === null) !== ($resolved === null)) {
            throw new \LogicException(\sprintf(
                'Filter family "%s" resolved "%s" to a filter that %s; it must accept the list the family declares.',
                $family->key(),
                $filter->key(),
                $resolved === null ? 'accepts no list' : ($resolved->isDelimited() ? 'accepts a delimited list' : 'accepts an array-only list'),
            ));
        }
    }
}
