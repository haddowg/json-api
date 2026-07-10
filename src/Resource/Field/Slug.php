<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for a URL slug string (lowercase alphanumerics
 * separated by single hyphens): presets a `SlugFormat` constraint and builds a
 * plain {@see Str}. Equivalent to `Str::make($name)->slug()`.
 */
final class Slug extends StrBuilder
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->slug();
    }
}
