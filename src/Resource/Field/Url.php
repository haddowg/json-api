<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for a URL string: presets a `UrlFormat` constraint
 * and builds a plain {@see Str}. Equivalent to `Str::make($name)->url()`.
 */
final class Url extends StrBuilder
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->url();
    }

    /**
     * Restricts the allowed URI schemes (e.g. `https`).
     *
     * @return static
     */
    public function allowedSchemes(string ...$schemes): static
    {
        return $this->url(\array_values($schemes));
    }
}
