<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Response Object — a required `description`, plus optional
 * `headers` (e.g. `Location` on a `201`) and a `content` map (omitted for a `204`).
 */
final readonly class Response implements \JsonSerializable
{
    /**
     * @param array<string, Header|Reference> $headers
     * @param array<string, MediaType>        $content media type → {@see MediaType}
     */
    public function __construct(
        public string $description,
        public array $headers = [],
        public array $content = [],
    ) {}

    /**
     * A response carrying a single JSON:API media type body.
     */
    public static function ofSchema(string $description, Schema|Reference $schema, string $mediaType = MediaType::JSON_API): self
    {
        return new self($description, content: [$mediaType => MediaType::ofSchema($schema)]);
    }

    /**
     * A bodyless response (e.g. `204 No Content`).
     */
    public static function noContent(string $description = 'No Content'): self
    {
        return new self($description);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['description' => $this->description];
        if ($this->headers !== []) {
            $headers = [];
            foreach ($this->headers as $name => $header) {
                $headers[$name] = $header->toArray();
            }
            $out['headers'] = $headers;
        }
        if ($this->content !== []) {
            $content = [];
            foreach ($this->content as $mediaType => $media) {
                $content[$mediaType] = $media->toArray();
            }
            $out['content'] = $content;
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        $object->description = $this->description;
        if ($this->headers !== []) {
            $headers = new \stdClass();
            foreach ($this->headers as $name => $header) {
                $headers->{$name} = $header->toJson();
            }
            $object->headers = $headers;
        }
        if ($this->content !== []) {
            $content = new \stdClass();
            foreach ($this->content as $mediaType => $media) {
                $content->{$mediaType} = $media->toJson();
            }
            $object->content = $content;
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
