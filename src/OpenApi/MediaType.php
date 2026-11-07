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

    /**
     * @param array<string, array{summary: string, value: mixed}|Reference> $examples named Example Objects, or references to them, emitted as `examples`
     */
    public function __construct(
        public Schema|Reference|null $schema = null,
        public mixed $example = null,
        public bool $hasExample = false,
        public array $examples = [],
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
        return new self($this->schema, $example, true, $this->examples);
    }

    /**
     * Returns a copy carrying named Example Objects, each a `summary` and a `value` or a
     * reference to a component, in place of a single `example`.
     *
     * @param array<string, array{summary: string, value: mixed}|Reference> $examples
     */
    public function withExamples(array $examples): self
    {
        return new self($this->schema, null, false, $examples);
    }

    public function withSchema(Schema|Reference|null $schema): self
    {
        return new self($schema, $this->example, $this->hasExample, $this->examples);
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
        if ($this->examples !== []) {
            $out['examples'] = \array_map(
                static fn(array|Reference $example): array => $example instanceof Reference ? $example->toArray() : $example,
                $this->examples,
            );
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
        if ($this->examples !== []) {
            $examples = new \stdClass();
            foreach ($this->examples as $name => $example) {
                $examples->{$name} = $example instanceof Reference
                    ? $example->toJson()
                    : (object) ['summary' => $example['summary'], 'value' => Serialization::convert($example['value'])];
            }
            $object->examples = $examples;
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
