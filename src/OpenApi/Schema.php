<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An immutable JSON Schema 2020-12 node — the dialect an OpenAPI 3.1 Schema
 * Object speaks. Built fluently from typed withers and serialized to a canonical
 * JSON-Schema array with {@see toArray()}.
 *
 * Every wither returns a **new** instance (the node is effectively immutable —
 * `clone`-then-assign, mirroring core's request/response VOs that cannot be
 * `readonly`). Keyword values are stored in a small typed map and emitted in a
 * stable, human-friendly order. Vendor extensions (`x-…`) are first-class so the
 * projector can attach `x-enum-varnames` / `x-enum-descriptions`.
 *
 * This is the projection target for {@see SchemaProjector}; it deliberately knows
 * nothing about resources, fields or constraints.
 */
final class Schema implements \JsonSerializable
{
    /**
     * The schema keywords, in insertion order, emitted by {@see toArray()}.
     *
     * @var array<string, mixed>
     */
    private array $keywords = [];

    /**
     * Vendor extension keywords (`x-…`), emitted after the standard keywords.
     *
     * @var array<string, mixed>
     */
    private array $extensions = [];

    /**
     * Whether this is the boolean `false` schema (see {@see never()}).
     */
    private bool $isNever = false;

    /**
     * A bare object node (`{}`) — the permissive default. Use the type factories
     * for a concrete type.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * The boolean `false` schema (JSON Schema 2020-12 / OpenAPI 3.1): it validates
     * **nothing**, so as a property value it forbids that property. It serialises to
     * a bare `false` rather than an object — `{"properties": {"id": false}}` reads as
     * "the `id` member must be absent". Only meaningful as a sub-schema (a property
     * value or list member), never a standalone document node.
     */
    public static function never(): self
    {
        $self = new self();
        $self->isNever = true;

        return $self;
    }

    /**
     * A node of the given primitive type (`string`, `integer`, `number`,
     * `boolean`, `array`, `object`, `null`).
     */
    public static function ofType(string $type): self
    {
        return self::create()->withType($type);
    }

    /**
     * A `$ref` node pointing at a component (e.g. `#/components/schemas/Status`).
     * In OpenAPI 3.1 / JSON Schema 2020-12 a Schema Object *is* a 2020-12 schema,
     * so a reference is simply `{"$ref": "<pointer>"}`; it may carry sibling
     * keywords (a `description` override) via the standard withers.
     */
    public static function ref(string $pointer): self
    {
        return self::create()->with('$ref', $pointer);
    }

    /**
     * A node whose `type` is the given primitive widened to allow JSON `null`
     * (the 2020-12 `["<type>", "null"]` union).
     */
    public static function nullable(string $type): self
    {
        return self::create()->withType($type, true);
    }

    /**
     * Sets the `type` keyword. When `$nullable` is true the type is emitted as a
     * two-member union `[<type>, "null"]` (widening an already-set scalar type is
     * handled by {@see asNullable()}).
     */
    public function withType(string $type, bool $nullable = false): self
    {
        $self = clone $this;
        $self->keywords['type'] = $nullable ? [$type, 'null'] : $type;

        return $self;
    }

    /**
     * Widens an existing scalar `type` to also allow `null`. A no-op when the
     * type is already a union or unset.
     */
    public function asNullable(): self
    {
        $type = $this->keywords['type'] ?? null;
        if (!\is_string($type)) {
            return $this;
        }

        $self = clone $this;
        $self->keywords['type'] = [$type, 'null'];

        return $self;
    }

    /**
     * Whether a scalar (non-union) `type` is set — the precondition for
     * {@see asNullable()} to widen it.
     */
    public function hasScalarType(): bool
    {
        return \is_string($this->keywords['type'] ?? null);
    }

    public function withFormat(string $format): self
    {
        return $this->with('format', $format);
    }

    public function withDescription(string $description): self
    {
        return $this->with('description', $description);
    }

    public function withTitle(string $title): self
    {
        return $this->with('title', $title);
    }

    /**
     * The currently-set description, or `null`.
     */
    public function description(): ?string
    {
        $description = $this->keywords['description'] ?? null;

        return \is_string($description) ? $description : null;
    }

    /**
     * Sets a single OpenAPI 3.1 `example` value. A `null` example is honoured.
     */
    public function withExample(mixed $example): self
    {
        return $this->with('example', $example);
    }

    public function withPattern(string $pattern): self
    {
        return $this->with('pattern', $pattern);
    }

    public function withMinimum(int|float $value): self
    {
        return $this->with('minimum', $value);
    }

    public function withMaximum(int|float $value): self
    {
        return $this->with('maximum', $value);
    }

    public function withExclusiveMinimum(int|float $value): self
    {
        return $this->with('exclusiveMinimum', $value);
    }

    public function withExclusiveMaximum(int|float $value): self
    {
        return $this->with('exclusiveMaximum', $value);
    }

    public function withMultipleOf(int|float $value): self
    {
        return $this->with('multipleOf', $value);
    }

    public function withMinLength(int $value): self
    {
        return $this->with('minLength', $value);
    }

    public function withMaxLength(int $value): self
    {
        return $this->with('maxLength', $value);
    }

    public function withMinItems(int $value): self
    {
        return $this->with('minItems', $value);
    }

    public function withMaxItems(int $value): self
    {
        return $this->with('maxItems', $value);
    }

    public function withUniqueItems(bool $value = true): self
    {
        return $this->with('uniqueItems', $value);
    }

    public function withMinProperties(int $value): self
    {
        return $this->with('minProperties', $value);
    }

    public function withMaxProperties(int $value): self
    {
        return $this->with('maxProperties', $value);
    }

    /**
     * Sets the `enum` keyword (the allowed value set).
     *
     * @param list<mixed> $values
     */
    public function withEnum(array $values): self
    {
        return $this->with('enum', \array_values($values));
    }

    /**
     * Sets the `const` keyword (a single allowed value).
     */
    public function withConst(mixed $value): self
    {
        return $this->with('const', $value);
    }

    public function withItems(self $items): self
    {
        return $this->with('items', $items);
    }

    /**
     * Sets `additionalProperties`. A {@see Schema} constrains the values; a `bool`
     * opens (`true`) or closes (`false`) the object.
     */
    public function withAdditionalProperties(self|bool $value): self
    {
        return $this->with('additionalProperties', $value);
    }

    /**
     * Adds one named property (object `properties` member).
     */
    public function withProperty(string $name, self $schema): self
    {
        $properties = $this->keywords['properties'] ?? [];
        if (!\is_array($properties)) {
            $properties = [];
        }
        $properties[$name] = $schema;

        return $this->with('properties', $properties);
    }

    /**
     * Sets the whole `properties` map at once.
     *
     * @param array<string, self> $properties
     */
    public function withProperties(array $properties): self
    {
        return $properties === [] ? $this : $this->with('properties', $properties);
    }

    /**
     * Sets the object `required` list.
     *
     * @param list<string> $names
     */
    public function withRequired(array $names): self
    {
        return $names === [] ? $this : $this->with('required', \array_values($names));
    }

    public function withNot(self $schema): self
    {
        return $this->with('not', $schema);
    }

    /**
     * @param list<self> $schemas
     */
    public function withAnyOf(array $schemas): self
    {
        return $this->with('anyOf', \array_values($schemas));
    }

    /**
     * @param list<self> $schemas
     */
    public function withAllOf(array $schemas): self
    {
        return $this->with('allOf', \array_values($schemas));
    }

    /**
     * @param list<self> $schemas
     */
    public function withOneOf(array $schemas): self
    {
        return $this->with('oneOf', \array_values($schemas));
    }

    /**
     * Sets a vendor extension keyword. The name is normalized to the `x-` prefix.
     */
    public function withExtension(string $name, mixed $value): self
    {
        $key = \str_starts_with($name, 'x-') ? $name : 'x-' . $name;

        $self = clone $this;
        $self->extensions[$key] = $value;

        return $self;
    }

    /**
     * Reads a standard keyword (testing / projection introspection), or `null`.
     */
    public function get(string $keyword): mixed
    {
        return $this->keywords[$keyword] ?? null;
    }

    /**
     * The canonical JSON-Schema 2020-12 representation: standard keywords in a
     * stable order, then vendor extensions. Nested {@see Schema} nodes recurse;
     * `list` arrays of nodes recurse element-wise.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $order = [
            '$ref',
            'type', 'format', 'title', 'description',
            'enum', 'const',
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
            'minLength', 'maxLength', 'pattern',
            'minItems', 'maxItems', 'uniqueItems', 'items',
            'minProperties', 'maxProperties', 'properties', 'required', 'additionalProperties',
            'allOf', 'anyOf', 'oneOf', 'not',
            'example',
        ];

        $out = [];
        foreach ($order as $keyword) {
            if (\array_key_exists($keyword, $this->keywords)) {
                $out[$keyword] = self::normalize($this->keywords[$keyword]);
            }
        }

        // Emit any keyword not in the canonical order list (forward-proofing).
        foreach ($this->keywords as $keyword => $value) {
            if (!\array_key_exists($keyword, $out)) {
                $out[$keyword] = self::normalize($value);
            }
        }

        foreach ($this->extensions as $keyword => $value) {
            $out[$keyword] = self::normalize($value);
        }

        return $out;
    }

    /**
     * The schema as the `stdClass` tree a JSON Schema validator (opis) consumes:
     * every {@see Schema} node (including an **empty** one) becomes a `stdClass`,
     * so an empty schema `{}` encodes as a JSON object — never an array. List
     * arrays (`enum`, `required`, `anyOf`, …) stay arrays. This is the form to
     * JSON-encode for a real document; {@see toArray()} is the assertion-friendly
     * nested-array form (which cannot distinguish an empty schema from an empty
     * list once flattened, hence this object-graph variant).
     */
    public function toJson(): \stdClass
    {
        // Recurse over the live keyword graph (not the flattened toArray() form) in
        // the same canonical order, so an empty Schema node becomes an empty object.
        $object = new \stdClass();
        foreach (\array_keys($this->toArray()) as $key) {
            $raw = \array_key_exists($key, $this->keywords) ? $this->keywords[$key] : $this->extensions[$key];
            $object->{$key} = self::convertValue($raw);
        }

        return $object;
    }

    /**
     * @return \stdClass
     */
    public function jsonSerialize(): object
    {
        return $this->toJson();
    }

    /**
     * Converts a keyword value to its JSON form: a {@see Schema} node (even an
     * empty one) becomes a `stdClass`; a list converts element-wise and stays a
     * JSON array; a string-keyed map becomes a `stdClass`; a scalar passes through.
     */
    private static function convertValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->isNever ? false : $value->toJson();
        }

        if (\is_array($value)) {
            if (\array_is_list($value)) {
                return \array_map(static fn(mixed $item): mixed => self::convertValue($item), $value);
            }

            $object = new \stdClass();
            foreach ($value as $key => $item) {
                $object->{$key} = self::convertValue($item);
            }

            return $object;
        }

        return $value;
    }

    /**
     * Recursively converts {@see Schema} nodes (and lists/maps of them) to arrays.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->isNever ? false : $value->toArray();
        }

        if (\is_array($value)) {
            return \array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
        }

        return $value;
    }

    /**
     * Sets one keyword, returning a new instance.
     */
    private function with(string $keyword, mixed $value): self
    {
        $self = clone $this;
        $self->keywords[$keyword] = $value;

        return $self;
    }
}
