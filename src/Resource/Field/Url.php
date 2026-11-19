<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for a URL string: presets a `UrlFormat` constraint
 * and builds a plain {@see Str}. Equivalent to `Str::make($name)->url()`.
 *
 * @template-covariant TValue = mixed
 *
 * @extends StrBuilder<TValue>
 */
final class Url extends StrBuilder
{
    /**
     * @return self<string>
     */
    public static function make(string $name = ''): self
    {
        return (new self($name))->url();
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
