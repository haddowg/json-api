<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for a UUID string: presets a `UuidFormat` constraint
 * and builds a plain {@see Str}. Equivalent to `Str::make($name)->uuid()`.
 *
 * @template-covariant TValue = mixed
 *
 * @extends StrBuilder<TValue>
 */
final class Uuid extends StrBuilder
{
    /**
     * @return self<string>
     */
    public static function make(string $name = ''): self
    {
        return (new self($name))->uuid();
    }

    /**
     * Narrows to a specific RFC 4122 UUID version.
     *
     * @return static
     */
    public function version(int $version): static
    {
        return $this->uuid($version);
    }
}
