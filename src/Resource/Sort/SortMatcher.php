<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Internal\KeyFamilyMatcher;

/**
 * Matches a requested `sort` key against a declared sort vocabulary, the one
 * rule every data layer applies so a key means the same thing on every path:
 * an exact {@see SortInterface::key()} first, then the {@see SortFamilyInterface}
 * with the longest prefix whose suffix pattern accepts the rest of the key.
 *
 * A family never matches its bare prefix, and the most specific family decides:
 * when its resolver returns `null` the key is unrecognized, it does not fall
 * through to a shorter prefix.
 */
final class SortMatcher
{
    /**
     * The sort `$key` (without a leading `-`) selects from `$sorts`, or `null` when
     * nothing declared answers it.
     *
     * @param list<SortInterface> $sorts
     *
     * @throws \LogicException when a family must resolve the key and no request was given, or a family resolves a sort whose key is not `$key`
     */
    public static function match(array $sorts, string $key, ?JsonApiRequestInterface $request = null): ?SortInterface
    {
        return KeyFamilyMatcher::match($sorts, $key, $request, static fn(SortInterface $sort): string => $sort->key(), 'sort');
    }

    /**
     * The {@see SortDirective}s for the request's `sort` fields (a leading `-` marks
     * descending), most significant first.
     *
     * @param list<string>        $requestedSort the request's `sort` fields, leading `-` preserved
     * @param list<SortInterface> $sorts         the sort vocabulary declared for the endpoint
     *
     * @return list<SortDirective>
     *
     * @throws SortingUnsupported    when a sort is requested and none is declared
     * @throws SortParamUnrecognized when a requested key matches nothing declared
     */
    public static function directives(array $requestedSort, array $sorts, ?JsonApiRequestInterface $request = null): array
    {
        if ($requestedSort === []) {
            return [];
        }

        if ($sorts === []) {
            throw new SortingUnsupported();
        }

        $directives = [];
        foreach ($requestedSort as $field) {
            $descending = \str_starts_with($field, '-');
            $key = $descending ? \substr($field, 1) : $field;

            $sort = self::match($sorts, $key, $request)
                ?? throw new SortParamUnrecognized($key);

            $directives[] = new SortDirective($sort, $descending);
        }

        return $directives;
    }

    /**
     * Merges sort vocabularies, a later sort replacing an earlier one with the same
     * key. An exact sort and a family sharing a key are different entries, since a
     * family never answers its bare prefix.
     *
     * @param list<SortInterface> ...$vocabularies
     *
     * @return list<SortInterface>
     */
    public static function merge(array ...$vocabularies): array
    {
        return KeyFamilyMatcher::merge(static fn(SortInterface $sort): string => $sort->key(), \array_values($vocabularies));
    }

    /**
     * The anchored PCRE for a family's suffix pattern fragment.
     *
     * @internal
     */
    public static function suffixRegex(string $pattern): string
    {
        return KeyFamilyMatcher::suffixRegex($pattern);
    }
}
