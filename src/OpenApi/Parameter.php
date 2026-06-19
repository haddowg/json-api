<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Parameter Object — a single operation parameter (`query`,
 * `path`, `header` or `cookie`). `name` and `in` are required; a `path` parameter
 * is implicitly required (the spec mandates `required: true`).
 *
 * The value is described by a `schema` (a {@see Schema} or a {@see Reference}); the
 * mutually-exclusive `content` form is not modelled (JSON:API query params are all
 * simple-style schema params).
 */
final readonly class Parameter implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ParameterLocation $in,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public Schema|Reference|null $schema = null,
    ) {}

    /**
     * A `query` parameter (the JSON:API case: `filter[…]`, `sort`, `include`,
     * `fields[…]`, `page[…]`).
     */
    public static function query(string $name, Schema|Reference|null $schema = null, ?string $description = null): self
    {
        return new self($name, ParameterLocation::Query, $description, schema: $schema);
    }

    /**
     * A `path` parameter — always required per the spec.
     */
    public static function path(string $name, Schema|Reference|null $schema = null, ?string $description = null): self
    {
        return new self($name, ParameterLocation::Path, $description, required: true, schema: $schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'in' => $this->in->value,
        ];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->required !== null) {
            $out['required'] = $this->required;
        }
        if ($this->deprecated !== null) {
            $out['deprecated'] = $this->deprecated;
        }
        if ($this->schema !== null) {
            $out['schema'] = $this->schema->toArray();
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = Serialization::toObject($this->toArray());
        if ($this->schema !== null) {
            $object->schema = $this->schema->toJson();
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
