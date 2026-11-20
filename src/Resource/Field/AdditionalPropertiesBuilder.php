<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable builder for an {@see AdditionalProperties} entry, which opens a declared
 * `meta` object to members it does not name.
 *
 * @template-covariant TValue = mixed
 *
 * @extends AbstractFieldBuilder<TValue>
 */
final class AdditionalPropertiesBuilder extends AbstractFieldBuilder
{
    private FieldInterface|FieldBuilderInterface|null $of = null;

    public function build(): AdditionalProperties
    {
        if ($this->name !== AdditionalProperties::NAME) {
            throw new \LogicException(\sprintf('An additionalProperties entry is named "%s"; declare one with AdditionalProperties::make().', AdditionalProperties::NAME));
        }
        $of = $this->of instanceof FieldBuilderInterface ? $this->of->build() : $this->of;
        if ($of instanceof RelationInterface || $of instanceof AdditionalProperties) {
            throw new \LogicException('An additionalProperties entry takes an attribute field as the value of its members, not a relation or another entry.');
        }

        return new AdditionalProperties($this->fieldState(), $of);
    }

    /**
     * Describes and validates the value of every member the object does not declare, with a
     * field whose name is never used; name it `'*'` by convention. Without it any JSON value
     * is accepted.
     *
     * @return static
     */
    public function of(FieldInterface|FieldBuilderInterface $value): static
    {
        $this->of = $value;

        return $this;
    }

    /**
     * Supplies the extra members a read renders: `$callback` is called with the model, the
     * request and the entry's name, and returns the members by name. They are merged after
     * the declared members. Like any `extractUsing()` hook it may take a fourth
     * {@see KeySelection} parameter, which is always `null` here, since `fields` never
     * narrows a `meta` object.
     *
     * @template TModel
     *
     * @param \Closure(TModel, \haddowg\JsonApi\Request\JsonApiRequestInterface, string, ?KeySelection): mixed $callback
     *
     * @return static
     */
    public function extractUsing(\Closure $callback): static
    {
        $this->extractUsing = $callback;

        return $this;
    }

    /**
     * Takes the written undeclared members: `$callback` is called with the model, the members
     * by name, the written object and the entry's name, and may return the model.
     *
     * @template TModel
     *
     * @param \Closure(TModel, array<string, mixed>, array<string, mixed>, string): mixed $callback
     *
     * @return static
     */
    public function fillUsing(\Closure $callback): static
    {
        $this->fillUsing = static fn(mixed $model, mixed $members, array $data, string $name): mixed => $callback($model, \is_array($members) ? $members : [], $data, $name);

        return $this;
    }
}
