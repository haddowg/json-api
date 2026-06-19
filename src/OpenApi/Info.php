<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Info Object — the document metadata. `title` and `version` are
 * required; `summary`, `description`, `termsOfService`, `contact` and `license`
 * are optional.
 */
final readonly class Info implements \JsonSerializable
{
    public function __construct(
        public string $title,
        public string $version,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $termsOfService = null,
        public ?Contact $contact = null,
        public ?License $license = null,
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
        );
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
