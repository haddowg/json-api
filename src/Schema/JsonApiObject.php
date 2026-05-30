<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema;

/**
 * The top-level "jsonapi" member of a JSON:API document.
 *
 * @see https://jsonapi.org/format/1.1/#document-jsonapi-object
 */
final readonly class JsonApiObject
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $version = '1.1',
        public array $meta = [],
    ) {}

    /**
     * @internal Serializes the object to its JSON:API representation.
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $result = [];

        if ($this->version !== '') {
            $result['version'] = $this->version;
        }

        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }
}
