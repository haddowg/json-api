<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;

/**
 * A zero-indexed array attribute (JSON `type: array`) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see ArrayListBuilder}; the resource **builds** it into this value
 * object before use.
 *
 * Its elements are scalars of {@see elementType()}, or structured: each one an
 * {@see Obj} or {@see OneOf} shape ({@see element()}) whose children read and write
 * keys inside that element. A write replaces the whole list, building every element
 * fresh from the request.
 */
final readonly class ArrayList extends AbstractFieldValue implements AcceptsDefaultOutputTimezoneInterface
{
    public function __construct(
        FieldState $state,
        private string $elementType = 'string',
        private bool $sorted = false,
        private Obj|OneOf|null $element = null,
    ) {
        parent::__construct($state);
    }

    public static function make(string $name): ArrayListBuilder
    {
        return new ArrayListBuilder($name);
    }

    /**
     * The declared JSON type of each element (default `string`), read by the OpenAPI
     * projection to type the `items` schema. A structured list reports `object`.
     */
    public function elementType(): string
    {
        return $this->elementType;
    }

    /**
     * The shape of each element of a structured list, or `null` for a list of scalars.
     * Its own name never appears on the wire; validate each element against its
     * children, or its variants, at `<list>/<index>`.
     */
    public function element(): Obj|OneOf|null
    {
        return $this->element;
    }

    public function withDefaultOutputTimezone(string $timezone): static
    {
        $element = $this->element?->withDefaultOutputTimezone($timezone);

        return $element === $this->element ? $this : new self($this->state, $this->elementType, $this->sorted, $element);
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->element === null || $this->state->serializeUsing !== null) {
            return parent::serialize($model, $request, $name);
        }

        if ($this->state->extractUsing !== null) {
            $element = $this->element;

            return $this->mapElements(
                ($this->state->extractUsing)($model, $request, $name),
                static fn(mixed $item): mixed => $element->serializeComputed($item, $request),
            );
        }

        $element = $this->element;

        return $this->mapElements(
            Accessor::get($model, $this->state->column ?? $name),
            static fn(mixed $item): mixed => $element->serializeStored($item, $request),
        );
    }

    /**
     * @param \Closure(mixed): mixed $render
     */
    private function mapElements(mixed $value, \Closure $render): mixed
    {
        if ($value instanceof \Traversable) {
            $value = \iterator_to_array($value, false);
        }
        if (!\is_array($value)) {
            return $value;
        }

        return \array_map($render, \array_values($value));
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->element === null || $this->state->fillUsing !== null || $this->state->deserializeUsing !== null) {
            return parent::hydrate($model, $value, $data, $request, $creating);
        }

        $column = $this->state->column;
        if ($column === null) {
            return $model;
        }

        if ($value === null) {
            return Accessor::set($model, $column, null);
        }

        if (!\is_array($value) || !\array_is_list($value)) {
            throw new AttributeValueInvalid($this->name(), \sprintf('expected a list, got %s', \is_array($value) ? 'an object' : \get_debug_type($value)));
        }

        // Every element is built fresh: a list has no stable identity to merge a stored
        // element onto, so a write replaces the whole list.
        $element = $this->element;
        $list = [];
        foreach ($value as $index => $item) {
            $pointer = $this->name() . '/' . $index;
            JsonObject::assertNotList($item, $pointer);
            $list[] = \is_array($item)
                ? JsonObject::hydrateChildren($pointer, static fn(): mixed => $element->hydrateElement($item, $data, $request, $creating))
                : $item;
        }

        return Accessor::set($model, $column, $list);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return $raw;
        }

        $list = \array_values($raw);
        if ($this->sorted) {
            \sort($list);
        }

        return $list;
    }
}
