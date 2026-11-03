<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

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
    private const string DELIMITER = "\x01";

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
        $families = [];
        foreach ($sorts as $sort) {
            if ($sort instanceof SortFamilyInterface) {
                $families[] = $sort;
            } elseif ($sort->key() === $key) {
                return $sort;
            }
        }

        \usort($families, static fn(SortFamilyInterface $a, SortFamilyInterface $b): int => \strlen($b->key()) <=> \strlen($a->key()));

        foreach ($families as $family) {
            $prefix = $family->key() . '.';
            if (!\str_starts_with($key, $prefix)) {
                continue;
            }

            $suffix = \substr($key, \strlen($prefix));
            if ($suffix === '' || \preg_match(self::suffixRegex($family->suffixPattern()), $suffix) !== 1) {
                continue;
            }

            if ($request === null) {
                throw new \LogicException(\sprintf('Sort family "%s" needs the request to resolve the sort key "%s"; pass it to the matcher.', $family->key(), $key));
            }

            $sort = $family->resolve($suffix, $request);
            if ($sort !== null && $sort->key() !== $key) {
                throw new \LogicException(\sprintf('Sort family "%s" resolved "%s" to a sort keyed "%s"; the key must be the full requested key.', $family->key(), $key, $sort->key()));
            }

            return $sort;
        }

        return null;
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
        $byKey = [];
        foreach ($vocabularies as $sorts) {
            foreach ($sorts as $sort) {
                $byKey[($sort instanceof SortFamilyInterface ? 'family:' : 'exact:') . $sort->key()] = $sort;
            }
        }

        return \array_values($byKey);
    }

    /**
     * The anchored PCRE for a family's suffix pattern fragment.
     *
     * @internal
     */
    public static function suffixRegex(string $pattern): string
    {
        return self::DELIMITER . '^(?:' . $pattern . ')$' . self::DELIMITER . 'Du';
    }
}
