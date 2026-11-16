<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereMorph}. {@see build()} freezes it into
 * the readonly {@see WhereMorph}.
 */
final class WhereMorphBuilder implements FilterBuilderInterface
{
    private string $relation;

    private ?string $description = null;

    private bool $hasExample = false;

    private mixed $example = null;

    private bool $deprecated = false;

    public function __construct(
        private string $key,
        ?string $relation = null,
    ) {
        $this->relation = $relation ?? $key;
    }

    public static function make(string $key, ?string $relation = null): self
    {
        return new self($key, $relation);
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
     * Sets an example value surfaced by the OpenAPI generator, shaped as the request
     * carries it: `['type' => 'rfps', 'id' => ['812']]`.
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
     * @throws \LogicException when the relation is not a single storage name
     */
    public function build(): WhereMorph
    {
        if ($this->relation === '' || \str_contains($this->relation, '.')) {
            throw new \LogicException(\sprintf(
                'WhereMorph filter "%s" names "%s", which is not a relation of the type it filters. It compares one polymorphic relation of that type, not a path.',
                $this->key,
                $this->relation,
            ));
        }

        return new WhereMorph($this->key, $this->relation, $this->description, $this->hasExample, $this->example, $this->deprecated);
    }
}
