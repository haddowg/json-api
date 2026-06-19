<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Reference Object — a `$ref` pointer to a component, optionally
 * carrying a `summary`/`description` override (both permitted alongside `$ref` in
 * OAS 3.1).
 *
 * A schema `$ref` is expressed on {@see Schema} itself (a Schema Object *is* a
 * 2020-12 schema, so `{"$ref": "…"}` is just a schema); this VO is for the
 * non-schema component slots (Response / Parameter / RequestBody / Header / …)
 * which reference by Reference Object.
 */
final readonly class Reference implements \JsonSerializable
{
    public function __construct(
        public string $ref,
        public ?string $summary = null,
        public ?string $description = null,
    ) {}

    /**
     * A reference to a component under `#/components/<section>/<name>`.
     */
    public static function to(string $section, string $name): self
    {
        return new self('#/components/' . $section . '/' . $name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['$ref' => $this->ref];
        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }
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
