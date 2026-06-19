<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Tag Object (a document-root tag definition) — a required `name`,
 * plus an optional `description` and `externalDocs`.
 *
 * Tags carry **no JSON:API meaning**; they group operations for the documentation
 * renderers. An {@see Operation} references a tag by name (see {@see Operation::$tags}).
 */
final readonly class Tag implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?ExternalDocumentation $externalDocs = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->externalDocs !== null) {
            $out['externalDocs'] = $this->externalDocs->toArray();
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
