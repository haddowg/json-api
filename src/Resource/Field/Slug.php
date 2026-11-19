<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for a URL slug string (lowercase alphanumerics
 * separated by single hyphens): presets a `SlugFormat` constraint and builds a
 * plain {@see Str}. Equivalent to `Str::make($name)->slug()`.
 *
 * @template-covariant TValue = mixed
 *
 * @extends StrBuilder<TValue>
 */
final class Slug extends StrBuilder
{
    /**
     * @return self<string>
     */
    public static function make(string $name = ''): self
    {
        return (new self($name))->slug();
    }
}
