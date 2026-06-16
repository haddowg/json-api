<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing\Internal;

/**
 * Recursively key-sorts an associative structure so an exact-match assertion
 * over two semantically-equal documents that differ only in key order still
 * passes, and — when they genuinely differ — PHPUnit prints a **stable**,
 * readable diff rather than one perturbed by incidental ordering (#64).
 *
 * List arrays (sequential integer keys) keep their order — order is significant
 * for a JSON:API collection — while their elements are normalised recursively.
 *
 * @internal
 */
final class Diff
{
    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    public static function normalise(array $value): array
    {
        if (\array_is_list($value)) {
            return \array_map(
                static fn(mixed $item): mixed => \is_array($item) ? self::normalise($item) : $item,
                $value,
            );
        }

        $sorted = $value;
        \ksort($sorted);

        $normalised = [];
        foreach ($sorted as $key => $item) {
            $normalised[$key] = \is_array($item) ? self::normalise($item) : $item;
        }

        return $normalised;
    }
}
