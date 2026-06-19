<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 External Documentation Object — a `url` plus an optional
 * `description`.
 */
final readonly class ExternalDocumentation implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public ?string $description = null,
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
        $out['url'] = $this->url;

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
