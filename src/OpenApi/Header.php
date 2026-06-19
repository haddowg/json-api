<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Header Object — describes a single response header (e.g.
 * `Location` on a `201`). Like a Parameter without `name`/`in` (a header's name is
 * the map key under `response.headers`).
 */
final readonly class Header implements \JsonSerializable
{
    public function __construct(
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public Schema|Reference|null $schema = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
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
