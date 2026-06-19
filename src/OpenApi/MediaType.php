<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Media Type Object — the schema (and optional example) for one
 * content type. JSON:API bodies use it under the `application/vnd.api+json` key of
 * a `content` map.
 */
final readonly class MediaType implements \JsonSerializable
{
    /**
     * The conventional JSON:API media type.
     */
    public const JSON_API = 'application/vnd.api+json';

    public function __construct(
        public Schema|Reference|null $schema = null,
        public mixed $example = null,
        public bool $hasExample = false,
    ) {}

    /**
     * A media type carrying the given schema (a {@see Schema} or a {@see Reference}
     * to a component schema).
     */
    public static function ofSchema(Schema|Reference $schema): self
    {
        return new self($schema);
    }

    /**
     * Returns a copy carrying a single `example` value (a `null` example is
     * honoured and emitted).
     */
    public function withExample(mixed $example): self
    {
        return new self($this->schema, $example, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->schema !== null) {
            $out['schema'] = $this->schema->toArray();
        }
        if ($this->hasExample) {
            $out['example'] = $this->example;
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        if ($this->schema !== null) {
            $object->schema = $this->schema->toJson();
        }
        if ($this->hasExample) {
            $object->example = Serialization::convert($this->example);
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
