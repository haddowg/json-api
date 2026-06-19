<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Request Body Object — the `content` map (required), plus an
 * optional `description` and a `required` flag.
 */
final readonly class RequestBody implements \JsonSerializable
{
    /**
     * @param array<string, MediaType> $content media type → {@see MediaType}
     */
    public function __construct(
        public array $content,
        public ?string $description = null,
        public ?bool $required = null,
    ) {}

    /**
     * A request body of a single media type (the JSON:API case:
     * `application/vnd.api+json` → the given schema).
     */
    public static function ofSchema(Schema|Reference $schema, string $mediaType = MediaType::JSON_API, bool $required = true): self
    {
        return new self([$mediaType => MediaType::ofSchema($schema)], required: $required);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        $content = [];
        foreach ($this->content as $mediaType => $media) {
            $content[$mediaType] = $media->toArray();
        }
        $out['content'] = $content;
        if ($this->required !== null) {
            $out['required'] = $this->required;
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        if ($this->description !== null) {
            $object->description = $this->description;
        }
        $content = new \stdClass();
        foreach ($this->content as $mediaType => $media) {
            $content->{$mediaType} = $media->toJson();
        }
        $object->content = $content;
        if ($this->required !== null) {
            $object->required = $this->required;
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
