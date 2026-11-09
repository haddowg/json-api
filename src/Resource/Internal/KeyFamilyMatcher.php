<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\KeyFamilyInterface;

/**
 * The matching rule {@see \haddowg\JsonApi\Resource\Sort\SortMatcher} and
 * {@see \haddowg\JsonApi\Resource\Filter\FilterMatcher} share: an exact key first, then
 * the {@see KeyFamilyInterface} with the longest prefix whose suffix pattern accepts the
 * rest of the key. A family never matches its bare prefix, and when the most specific
 * family resolves `null` the key is unrecognized rather than falling through.
 *
 * @internal
 */
final class KeyFamilyMatcher
{
    private const string DELIMITER = "\x01";

    /**
     * @template T of object
     *
     * @param list<T>          $members the declared vocabulary
     * @param \Closure(T): string $keyOf   the exact key of a member
     * @param string           $noun    `sort` or `filter`, for the wiring errors
     * @param (\Closure(KeyFamilyInterface, T): void)|null $verify checks a resolved member against its family
     *
     * @return T|null
     *
     * @throws \LogicException when a family must resolve the key and no request was given, or a family resolves a member whose key is not `$key`
     */
    public static function match(array $members, string $key, ?JsonApiRequestInterface $request, \Closure $keyOf, string $noun, ?\Closure $verify = null): ?object
    {
        $families = [];
        foreach ($members as $member) {
            if ($member instanceof KeyFamilyInterface) {
                $families[] = $member;
            } elseif ($keyOf($member) === $key) {
                return $member;
            }
        }

        \usort($families, static fn(KeyFamilyInterface $a, KeyFamilyInterface $b): int => \strlen($b->key()) <=> \strlen($a->key()));

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
                throw new \LogicException(\sprintf('%s family "%s" needs the request to resolve the %s key "%s"; pass it to the matcher.', \ucfirst($noun), $family->key(), $noun, $key));
            }

            /** @var T|null $resolved */
            $resolved = $family->resolve($suffix, $request);
            if ($resolved !== null && $keyOf($resolved) !== $key) {
                throw new \LogicException(\sprintf('%s family "%s" resolved "%s" to a %s keyed "%s"; the key must be the full requested key.', \ucfirst($noun), $family->key(), $key, $noun, $keyOf($resolved)));
            }
            if ($resolved !== null && $verify !== null) {
                $verify($family, $resolved);
            }

            return $resolved;
        }

        return null;
    }

    /**
     * Merges vocabularies, a later member replacing an earlier one with the same key. An
     * exact member and a family sharing a key are different entries, since a family never
     * answers its bare prefix.
     *
     * @template T of object
     *
     * @param \Closure(T): string $keyOf
     * @param list<list<T>>    $vocabularies
     *
     * @return list<T>
     */
    public static function merge(\Closure $keyOf, array $vocabularies): array
    {
        $byKey = [];
        foreach ($vocabularies as $members) {
            foreach ($members as $member) {
                $byKey[($member instanceof KeyFamilyInterface ? 'family:' : 'exact:') . $keyOf($member)] = $member;
            }
        }

        return \array_values($byKey);
    }

    /**
     * The anchored PCRE for a family's suffix pattern fragment.
     */
    public static function suffixRegex(string $pattern): string
    {
        return self::DELIMITER . '^(?:' . $pattern . ')$' . self::DELIMITER . 'Du';
    }

    /**
     * Throws when `$pattern` is empty or not a valid regular expression.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidSuffixPattern(string $pattern, string $noun, string $prefix): void
    {
        if ($pattern === '' || @\preg_match(self::suffixRegex($pattern), '') === false) {
            throw new \InvalidArgumentException(\sprintf('The suffix pattern "%s" of %s family "%s" is not a valid regular expression.', $pattern, $noun, $prefix));
        }
    }
}
