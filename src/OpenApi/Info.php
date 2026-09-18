<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Info Object — the document metadata. `title` and `version` are
 * required; `summary`, `description`, `termsOfService`, `contact` and `license`
 * are optional.
 *
 * Vendor extensions (`x-…`) are carried alongside and emitted after the standard
 * members — the projector stamps `x-generator` here (see {@see GeneratorContract}).
 */
final readonly class Info implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $extensions vendor extensions (`x-…`), emitted after the standard members
     */
    public function __construct(
        public string $title,
        public string $version,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $termsOfService = null,
        public ?Contact $contact = null,
        public ?License $license = null,
        private array $extensions = [],
    ) {}

    public function withDescription(?string $description): self
    {
        return new self(
            $this->title,
            $this->version,
            $this->summary,
            $description,
            $this->termsOfService,
            $this->contact,
            $this->license,
            $this->extensions,
        );
    }

    public function withContact(?Contact $contact): self
    {
        return new self(
            $this->title,
            $this->version,
            $this->summary,
            $this->description,
            $this->termsOfService,
            $contact,
            $this->license,
            $this->extensions,
        );
    }

    public function withLicense(?License $license): self
    {
        return new self(
            $this->title,
            $this->version,
            $this->summary,
            $this->description,
            $this->termsOfService,
            $this->contact,
            $license,
            $this->extensions,
        );
    }

    /**
     * Sets a vendor extension keyword (the name is normalized to the `x-` prefix).
     */
    public function withExtension(string $name, mixed $value): self
    {
        $key = \str_starts_with($name, 'x-') ? $name : 'x-' . $name;

        return new self(
            $this->title,
            $this->version,
            $this->summary,
            $this->description,
            $this->termsOfService,
            $this->contact,
            $this->license,
            [...$this->extensions, $key => $value],
        );
    }

    /**
     * Reads a vendor extension keyword (`x-…`), or `null` when absent. The name is
     * normalized to the `x-` prefix, so `extension('generator')` reads `x-generator`.
     */
    public function extension(string $name): mixed
    {
        $key = \str_starts_with($name, 'x-') ? $name : 'x-' . $name;

        return $this->extensions[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['title' => $this->title];
        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->termsOfService !== null) {
            $out['termsOfService'] = $this->termsOfService;
        }
        if ($this->contact !== null) {
            $out['contact'] = $this->contact->toArray();
        }
        if ($this->license !== null) {
            $out['license'] = $this->license->toArray();
        }
        $out['version'] = $this->version;
        foreach ($this->extensions as $key => $value) {
            $out[$key] = $value;
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
