<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Server Variable Object — a `default` (required), an optional
 * `enum` of allowed values, and an optional `description`.
 */
final readonly class ServerVariable implements \JsonSerializable
{
    /**
     * @param list<string> $enum
     */
    public function __construct(
        public string $default,
        public array $enum = [],
        public ?string $description = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->enum !== []) {
            $out['enum'] = \array_values($this->enum);
        }
        $out['default'] = $this->default;
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        return Serialization::toObject($this->toArray());
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
