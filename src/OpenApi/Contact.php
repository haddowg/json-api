<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Contact Object — the contact information for the API, all members
 * optional.
 */
final readonly class Contact implements \JsonSerializable
{
    public function __construct(
        public ?string $name = null,
        public ?string $url = null,
        public ?string $email = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        if ($this->email !== null) {
            $out['email'] = $this->email;
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
