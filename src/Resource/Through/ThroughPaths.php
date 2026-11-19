<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

/**
 * @internal
 */
final class ThroughPaths
{
    /**
     * `$path` when it names at least one relation and no empty segment.
     *
     * @throws \InvalidArgumentException
     */
    public static function checked(string $path): string
    {
        foreach (\explode('.', $path) as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a path of relations; name each relation once, separated by dots.', $path));
            }
        }

        return $path;
    }
}
