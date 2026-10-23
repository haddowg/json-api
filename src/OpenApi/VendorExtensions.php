<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The key rules for the vendor extension (`x-…`) maps the OpenAPI value objects
 * carry.
 *
 * @internal
 */
final class VendorExtensions
{
    /**
     * Normalises one extension name to its `x-` key, the way {@see Schema::withExtension()}
     * does (`feature` becomes `x-feature`), then validates it.
     *
     * @throws \InvalidArgumentException
     */
    public static function key(string $name): string
    {
        return self::validKey(\str_starts_with($name, 'x-') ? $name : 'x-' . $name);
    }

    /**
     * Normalises every key of a map with {@see key()}.
     *
     * @param array<string, mixed> $extensions
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalise(array $extensions): array
    {
        $out = [];
        foreach ($extensions as $name => $value) {
            $out[self::key((string) $name)] = $value;
        }

        return $out;
    }

    /**
     * Checks a map handed to a constructor as is: every key must already be an
     * `x-` key.
     *
     * @param array<array-key, mixed> $extensions
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(array $extensions): void
    {
        foreach (\array_keys($extensions) as $key) {
            self::validKey((string) $key);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validKey(string $key): string
    {
        if (!\str_starts_with($key, 'x-') || $key === 'x-') {
            throw new \InvalidArgumentException(\sprintf(
                'OpenAPI vendor extension "%s" is invalid: an extension key is "x-" followed by a name.',
                $key,
            ));
        }

        $lower = \strtolower($key);
        if (\str_starts_with($lower, 'x-oai-') || \str_starts_with($lower, 'x-oas-')) {
            throw new \InvalidArgumentException(\sprintf(
                'OpenAPI vendor extension "%s" is invalid: the "x-oai-" and "x-oas-" prefixes are reserved by the OpenAPI Initiative.',
                $key,
            ));
        }

        return $key;
    }
}
