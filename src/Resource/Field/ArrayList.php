<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Internal\KeyTrim;
use haddowg\JsonApi\Resource\ValueTypes;

/**
 * A zero-indexed array attribute (JSON `type: array`) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see ArrayListBuilder}; the resource **builds** it into this value
 * object before use.
 *
 * Its elements are scalars of {@see elementType()}, structured: each one an
 * {@see Obj} or {@see OneOf} shape ({@see element()}) whose children read and write
 * keys inside that element, or each one of several primitive types, a {@see Union}
 * ({@see elementUnion()}). A write replaces the whole list, building every element
 * fresh from the request.
 *
 * A structured list whose element is an {@see Obj} declaring `selectableKeys()` lets a
 * request select keys of every item as `<attr>.*.<key>`: its one selectable key is
 * {@see KeySelection::ITEMS}, and each item renders trimmed to the selection inside it.
 */
final readonly class ArrayList extends AbstractFieldValue implements NamedSchemaInterface, AcceptsDefaultOutputTimezoneInterface, SelectsKeysInterface
{
    public function __construct(
        FieldState $state,
        private string $elementType = 'string',
        private bool $sorted = false,
        private Obj|OneOf|null $element = null,
        private ?Union $elementUnion = null,
    ) {
        parent::__construct($state);
    }

    /**
     * `$name` may be left out for a {@see Union} member, whose name is never used.
     *
     * @return ArrayListBuilder<list<string>>
     */
    public static function make(string $name = ''): ArrayListBuilder
    {
        return new ArrayListBuilder($name);
    }

    /**
     * The declared JSON type of each element (default `string`), read by the OpenAPI
     * projection to type the `items` schema. A structured list reports `object`, and a
     * list of unions its {@see Union::valueType()}, as `string|number`.
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

    /**
     * The union each element is one member of, or `null` when the elements are scalars of
     * {@see elementType()} or structured. Its own name never appears on the wire;
     * validate each element against the member its JSON type selects, at
     * `<list>/<index>`.
     */
    public function elementUnion(): ?Union
    {
        return $this->elementUnion;
    }

    /**
     * The element whose keys a request may select in every item, or `null` when the list
     * is not of an {@see Obj} declaring `selectableKeys()`.
     */
    public function selectableElement(): ?Obj
    {
        return $this->element instanceof Obj && $this->element->selectableKeys() !== null ? $this->element : null;
    }

    /**
     * Accepts only {@see KeySelection::ITEMS}, and only when the list has a
     * {@see selectableElement()}. A list declares nothing itself: its element does.
     */
    public function selectableKeys(): ?SelectableKeys
    {
        return $this->selectableElement() === null ? null : new SelectableKeys(pattern: '\\*');
    }

    public function keyField(string $key): ?FieldInterface
    {
        return $key === KeySelection::ITEMS ? $this->selectableElement() : null;
    }

    public function withDefaultOutputTimezone(string $timezone): static
    {
        $element = $this->element?->withDefaultOutputTimezone($timezone);
        $union = $this->elementUnion?->withDefaultOutputTimezone($timezone);

        return $element === $this->element && $union === $this->elementUnion ? $this : new self($this->state, $this->elementType, $this->sorted, $element, $union);
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->serializeUsing === null && $this->state->extractUsing !== null && $this->element === null) {
            return $this->serializeComputedValue($this->backingValue(KeyTrim::extract($this->state->extractUsing, $model, $request, $name, null)));
        }

        return $this->serializeItems($model, $request, $name, null);
    }

    /**
     * The list with every item trimmed to the selection inside {@see KeySelection::ITEMS},
     * or every item whole when the request selected them whole. Its length and order are
     * kept, and an item holding none of the selected keys renders as an empty object.
     */
    public function serializeSelection(mixed $model, JsonApiRequestInterface $request, string $name, KeySelection $only): mixed
    {
        if ($this->state->serializeUsing !== null || $this->element === null) {
            return KeyTrim::trim($this->serialize($model, $request, $name), $only);
        }

        return $this->serializeItems($model, $request, $name, $only);
    }

    private function serializeItems(mixed $model, JsonApiRequestInterface $request, string $name, ?KeySelection $only): mixed
    {
        if ($this->element === null || $this->state->serializeUsing !== null) {
            return parent::serialize($model, $request, $name);
        }

        $element = $this->element;
        $items = $only?->items();
        $computed = $this->state->extractUsing !== null;
        $render = $element instanceof Obj
            ? static fn(mixed $item): mixed => $computed ? $element->serializeComputed($item, $request, $items) : $element->serializeStored($item, $request, $items)
            : static fn(mixed $item): mixed => $computed ? $element->serializeComputed($item, $request) : $element->serializeStored($item, $request);

        return $this->mapElements(
            $this->state->extractUsing !== null
                ? KeyTrim::extract($this->state->extractUsing, $model, $request, $name, $only)
                : Accessor::get($model, $this->state->column ?? $name),
            $render,
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
        if ($this->elementUnion !== null && !$this->hydratesThroughHookFor($creating)) {
            $column = $this->state->column;

            return $column === null || !\is_array($value) ? parent::hydrate($model, $value, $data, $request, $creating) : Accessor::set($model, $column, $this->castElementsAt($value, $request, $creating));
        }

        if ($this->element === null || $this->hydratesThroughHookFor($creating)) {
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

    /**
     * A list of scalars nested in an {@see Obj} keeps its elements' backing values; a
     * structured list or a list of unions builds its elements as {@see hydrate()} does,
     * since those are nested already.
     *
     * @internal
     */
    public function hydrateNested(mixed $nested, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        return $this->element === null && $this->elementUnion === null
            ? parent::hydrateNested($nested, $value, $data, $request, $creating)
            : $this->hydrate($nested, $value, $data, $request, $creating);
    }

    /**
     * Casts each element of a list of unions through the member its JSON type selects, a
     * date member's value stored as a string. A failing cast throws with its pointer below
     * the list, as `2` or `2/0`. The elements of any other list are returned as sent.
     *
     * @internal
     *
     * @param array<array-key, mixed> $value
     *
     * @throws AttributeValueInvalid
     */
    public function castUnionElements(array $value, ?JsonApiRequestInterface $request, bool $creating, bool $nested = false): mixed
    {
        $union = $this->elementUnion;
        if ($union === null) {
            return $nested ? $this->castNestedWireValue($value) : $this->castWireValueFor($value, $creating);
        }

        $list = [];
        foreach (\array_values($value) as $index => $item) {
            try {
                $list[] = $union->cast($item, true, $request, $creating);
            } catch (AttributeValueInvalid $e) {
                throw new AttributeValueInvalid($e->attribute === '' ? (string) $index : $index . '/' . $e->attribute, $e->reason);
            }
        }

        return $list;
    }

    /**
     * Checks each element of a structured list or a list of unions against the shape it
     * documents.
     *
     * @internal
     */
    public function assertWireFormat(mixed $value): void
    {
        if (!\is_array($value) || !\array_is_list($value) || ($this->element === null && $this->elementUnion === null)) {
            return;
        }

        foreach ($value as $index => $item) {
            $pointer = $this->name() . '/' . $index;
            if ($this->elementUnion !== null) {
                try {
                    $this->elementUnion->assertWireFormat($item);
                } catch (AttributeValueInvalid $e) {
                    $below = self::pointerBelow($e, $this->elementUnion);

                    throw new AttributeValueInvalid($below === '' ? $pointer : $pointer . '/' . $below, $e->reason);
                }

                continue;
            }

            if (!\is_array($item)) {
                continue;
            }

            $element = $this->element;
            $name = $element instanceof OneOf ? $element->variantNameOf($item) : null;
            $children = match (true) {
                $element instanceof Obj => $element->children(),
                $element instanceof OneOf && $name !== null => $element->variants()[$name]->children(),
                default => [],
            };
            JsonObject::hydrateChildren($pointer, static function () use ($children, $item): void {
                Obj::assertChildrenWireFormat($children, $item);
            });
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @throws AttributeValueInvalid
     */
    private function castElementsAt(array $value, ?JsonApiRequestInterface $request, bool $creating): mixed
    {
        try {
            return $this->castUnionElements($value, $request, $creating);
        } catch (AttributeValueInvalid $e) {
            throw new AttributeValueInvalid($this->name() . '/' . $e->attribute, $e->reason);
        }
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return $this->elementUnion !== null && \is_array($value) ? $this->castElementsAt($value, null, true) : $value;
    }

    /**
     * The backed enum an `In` in this list's `each()` was sourced from, for a list of
     * scalars, or `null`.
     */
    protected function declaredEnum(): ?string
    {
        return $this->eachEnum(null, anyWrite: true);
    }

    /**
     * The backed enum an `In` in this list's `each()` declares for the write, for a list
     * of scalars, or `null`.
     */
    protected function writtenEnum(?bool $creating): ?string
    {
        return $this->eachEnum($creating, anyWrite: false);
    }

    /**
     * @return class-string<\BackedEnum>|null
     */
    private function eachEnum(?bool $creating, bool $anyWrite): ?string
    {
        if ($this->element !== null || $this->elementUnion !== null) {
            return null;
        }

        foreach ($this->state->constraints as $constraint) {
            if ($constraint instanceof Each && self::appliesToWrite($constraint->context(), $creating, $anyWrite)
                && ($enum = self::enumClassIn($constraint->constraints, $creating, $anyWrite)) !== null) {
                return $enum;
            }
        }

        return null;
    }

    /**
     * Normalizes each element: an `integer` list's whole numbers become `int`s, a
     * `number` list's integers `float`s, and a structured list or a list of unions
     * normalizes each element through its shape.
     *
     * @internal
     */
    public function normalizeWireValue(mixed $value): mixed
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            return $value;
        }

        $element = $this->element ?? $this->elementUnion;
        if ($element !== null) {
            return \array_map(static fn(mixed $item): mixed => $element->normalizeWireValue($item), $value);
        }

        return \array_map($this->normalizeNumber(...), $value);
    }

    /**
     * An element of an `integer` list that is a whole `float` as an `int`, and one of a
     * `number` list that is an `int` as a `float`.
     */
    private function normalizeNumber(mixed $item): mixed
    {
        return match (true) {
            $this->elementType === ValueTypes::INTEGER && \is_float($item) && ValueTypes::matches(ValueTypes::INTEGER, $item) => (int) $item,
            $this->elementType === ValueTypes::NUMBER && \is_int($item) => (float) $item,
            default => $item,
        };
    }

    /**
     * Each element of a list of scalars declaring a backed enum in its `each()` becomes
     * that enum's case.
     *
     * @throws AttributeValueInvalid
     */
    protected function toWrittenCase(mixed $value, ?bool $creating): mixed
    {
        $enum = $this->writtenEnum($creating);
        if ($enum === null || !\is_array($value)) {
            return $value;
        }

        $list = [];
        foreach ($value as $index => $item) {
            $list[$index] = $this->caseOf($enum, $item, $this->name() . '/' . $index);
        }

        return $list;
    }

    protected function backingValue(mixed $raw): mixed
    {
        return \is_array($raw) && $this->declaredEnum() !== null
            ? \array_map(static fn(mixed $item): mixed => $item instanceof \BackedEnum ? $item->value : $item, $raw)
            : $raw;
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return $raw;
        }

        if ($this->elementUnion !== null) {
            return \array_map($this->elementUnion->render(...), \array_values($raw));
        }

        $list = \array_map($this->normalizeNumber(...), \array_values($raw));
        if ($this->sorted) {
            \sort($list);
        }

        return $list;
    }

    public function componentName(): ?string
    {
        return $this->state->component;
    }
}
