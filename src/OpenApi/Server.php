<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Server Object — a `url` (required, may contain `{variable}`
 * templates), an optional `description`, and an optional `variables` map.
 */
final readonly class Server implements \JsonSerializable
{
    /**
     * @param array<string, ServerVariable> $variables
     */
    public function __construct(
        public string $url,
        public ?string $description = null,
        public array $variables = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['url' => $this->url];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->variables !== []) {
            $variables = [];
            foreach ($this->variables as $name => $variable) {
                $variables[$name] = $variable->toArray();
            }
            $out['variables'] = $variables;
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
