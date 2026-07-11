<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereThrough} value filter.
 * {@see build()} freezes the accumulated state into the readonly {@see WhereThrough}
 * value object.
 */
final class WhereThroughBuilder implements FilterBuilderInterface
{
    use BuildsValueConstraints;

    private string $path;

    private string $operator = '=';

    /**
     * @var \Closure(mixed): mixed|null
     */
    private ?\Closure $deserialize = null;

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

    public function build(): WhereThrough
    {
        return new WhereThrough($this->key, $this->path, $this->operator, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example);
    }

    /**
     * Sets the comparison operator applied at the leaf segment. Same vocabulary as
     * {@see Where} (`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`).
     */
    public function operator(string $operator): self
    {
        $this->operator = $operator;

        return $this;
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): self
    {
        $this->deserialize = $deserialize;

        return $this;
    }
}
