<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 License Object — a required `name`, plus an SPDX `identifier`
 * **or** a `url` (mutually exclusive per the spec; the caller is responsible for
 * supplying at most one).
 */
final readonly class License implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $identifier = null,
        public ?string $url = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name];
        if ($this->identifier !== null) {
            $out['identifier'] = $this->identifier;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
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
