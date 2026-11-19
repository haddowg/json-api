<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereSet}. {@see build()} freezes it into
 * the readonly {@see WhereSet}, reading a declared default and example into lists of
 * booleans.
 */
final class WhereSetBuilder implements FilterBuilderInterface
{
    use BuildsThrough;
    private string $column;

    private mixed $default = null;

    private bool $hasDefault = false;

    private ?string $description = null;

    private bool $hasExample = false;

    private mixed $example = null;

    private bool $deprecated = false;

    public function __construct(
        private string $key,
        ?string $column = null,
    ) {
        $this->column = $column ?? $key;
    }

    public static function make(string $key, ?string $column = null): self
    {
        return new self($key, $column);
    }

    /**
     * Declares the value to apply when the request omits this filter's key, shaped as
     * the request would carry it: `false`, `[false]` or `'true,false'`. A requested value
     * always wins ({@see HasDefaultValue}).
     */
    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Sets a human-readable description surfaced by the OpenAPI generator.
     */
    public function describedAs(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Sets an example value surfaced by the OpenAPI generator, shaped as a default is.
     */
    public function example(mixed $example): self
    {
        $this->example = $example;
        $this->hasExample = true;

        return $this;
    }

    /**
     * Marks the filter deprecated in the OpenAPI document. Documentation only.
     */
    public function deprecated(): self
    {
        $this->deprecated = true;

        return $this;
    }

    /**
     * @throws \InvalidArgumentException when the default or the example is not a boolean or a list of booleans
     */
    public function build(): WhereSet
    {
        return new WhereSet(
            $this->key,
            $this->column,
            $this->hasDefault ? $this->booleans('default', $this->default) : null,
            [WhereSet::booleanConstraint()],
            $this->description,
            $this->hasExample,
            $this->hasExample ? $this->booleans('example', $this->example) : null,
            $this->deprecated,
            through: $this->through,
        );
    }

    /**
     * @return list<bool>
     */
    private function booleans(string $what, mixed $value): array
    {
        $members = ListValue::delimited()->members($value);
        $booleans = [];
        foreach ($members as $member) {
            $boolean = \is_array($member) ? null : WhereSet::boolean($member);
            if ($boolean === null) {
                throw new \InvalidArgumentException(\sprintf(
                    'WhereSet filter "%s" declares a %s that is not a boolean or a list of booleans.',
                    $this->key,
                    $what,
                ));
            }
            $booleans[] = $boolean;
        }

        return $booleans;
    }
}
