<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * Represents a JSON:API link.
 *
 * A link may serialize either to a bare URL string or, when meta is present, to
 * a link object. This is the base class for the richer {@see LinkObject} and
 * {@see ProfileLinkObject}; it is readonly but not final so those subclasses
 * (themselves readonly) may extend it.
 *
 * @see https://jsonapi.org/format/1.1/#document-links
 */
readonly class Link
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $href,
        public array $meta = [],
    ) {}

    /**
     * @internal
     *
     * @return string|array<string, mixed>
     */
    public function transform(string $baseUri): string|array
    {
        $href = $this->href === '' ? $this->href : $baseUri . $this->href;

        if ($this->meta === []) {
            return $href;
        }

        return [
            'href' => $href,
            'meta' => $this->meta,
        ];
    }
}
