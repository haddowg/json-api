<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * Represents a JSON:API link object, which always serializes to an object form.
 *
 * Beyond the bare `href` of a {@see Link}, the JSON:API 1.1 link object permits
 * `rel`, `describedby`, `title`, `type`, `hreflang` and `meta` members. All of
 * the string members are optional and omitted from {@see transform()} when empty.
 *
 * @see https://jsonapi.org/format/1.1/#document-links-link-object
 */
readonly class LinkObject extends Link
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        string $href,
        public string $rel = '',
        public string $title = '',
        public string $type = '',
        public string $hreflang = '',
        array $meta = [],
        public ?Link $describedby = null,
    ) {
        parent::__construct($href, $meta);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function transform(string $baseUri): array
    {
        $link = [
            'href' => $this->href === '' ? $this->href : $baseUri . $this->href,
        ];

        if ($this->rel !== '') {
            $link['rel'] = $this->rel;
        }

        if ($this->title !== '') {
            $link['title'] = $this->title;
        }

        if ($this->type !== '') {
            $link['type'] = $this->type;
        }

        if ($this->hreflang !== '') {
            $link['hreflang'] = $this->hreflang;
        }

        if ($this->meta !== []) {
            $link['meta'] = $this->meta;
        }

        if ($this->describedby !== null) {
            $link['describedby'] = $this->describedby->transform($baseUri);
        }

        return $link;
    }
}
