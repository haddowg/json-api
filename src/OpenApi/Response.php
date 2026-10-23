<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Response Object — a required `description`, plus optional
 * `headers` (e.g. `Location` on a `201`) and a `content` map (omitted for a `204`).
 * Vendor extensions (`x-…`) are emitted after the standard members.
 */
final readonly class Response implements \JsonSerializable
{
    /**
     * @param array<string, Header|Reference> $headers
     * @param array<string, MediaType>        $content    media type → {@see MediaType}
     * @param array<string, mixed>            $extensions vendor extensions, each key `x-…`
     */
    public function __construct(
        public string $description,
        public array $headers = [],
        public array $content = [],
        private array $extensions = [],
    ) {
        VendorExtensions::assertValid($extensions);
    }

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
     * Sets a vendor extension. The name is normalised to the `x-` prefix.
     *
     * @throws \InvalidArgumentException for a reserved `x-oai-`/`x-oas-` name
     */
    public function withExtension(string $name, mixed $value): self
    {
        return $this->withExtensions([$name => $value]);
    }

    /**
     * Merges several vendor extensions over the existing ones, each name normalised
     * to the `x-` prefix.
     *
     * @param array<string, mixed> $extensions
     *
     * @throws \InvalidArgumentException for a reserved `x-oai-`/`x-oas-` name
     */
    public function withExtensions(array $extensions): self
    {
        return new self(
            $this->description,
            $this->headers,
            $this->content,
            [...$this->extensions, ...VendorExtensions::normalise($extensions)],
        );
    }

    /**
     * Reads a vendor extension, or `null` when absent. The name is normalised to
     * the `x-` prefix.
     */
    public function extension(string $name): mixed
    {
        return $this->extensions[\str_starts_with($name, 'x-') ? $name : 'x-' . $name] ?? null;
    }

    /**
     * Every vendor extension, keyed `x-…`.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    public function withDescription(string $description): self
    {
        return new self($description, $this->headers, $this->content, $this->extensions);
    }

    /**
     * @param array<string, Header|Reference> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->description, $headers, $this->content, $this->extensions);
    }

    /**
     * Returns a copy with one header added or replaced.
     */
    public function withHeader(string $name, Header|Reference $header): self
    {
        $headers = $this->headers;
        $headers[$name] = $header;

        return $this->withHeaders($headers);
    }

    /**
     * @param array<string, MediaType> $content media type → {@see MediaType}
     */
    public function withContent(array $content): self
    {
        return new self($this->description, $this->headers, $content, $this->extensions);
    }

    /**
     * Returns a copy with one media type's body added or replaced.
     */
    public function withMediaType(string $mediaType, MediaType $media): self
    {
        $content = $this->content;
        $content[$mediaType] = $media;

        return $this->withContent($content);
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
        foreach ($this->extensions as $key => $value) {
            $out[$key] = $value;
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
        foreach ($this->extensions as $key => $value) {
            $object->{$key} = Serialization::convert($value);
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
