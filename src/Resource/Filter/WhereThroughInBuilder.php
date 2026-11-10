<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereThroughIn} filter. Its value is a
 * delimited list unless {@see acceptsList()} says `delimited: false`. {@see build()}
 * freezes the accumulated state into the readonly {@see WhereThroughIn} value object.
 */
final class WhereThroughInBuilder implements FilterBuilderInterface
{
    use BuildsListValue;
    use BuildsValueConstraints;

    private string $path;

    public function __construct(
        private string $key,
        ?string $path = null,
    ) {
        $this->path = $path ?? $key;
    }

    public static function make(string $key, ?string $path = null): self
    {
        return new self($key, $path);
    }

    public function build(): WhereThroughIn
    {
        return new WhereThroughIn($this->key, $this->path, $this->builtListValue(null) ?? ListValue::delimited(), $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated);
    }
}
