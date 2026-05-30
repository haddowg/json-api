<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * Base for the keyed link-container value objects (`DocumentLinks`, `ErrorLinks`,
 * `ResourceLinks`, `RelationshipLinks`).
 *
 * Construct-only and immutable: links are supplied at construction and never
 * mutated afterwards. Null entries are dropped so absent relations are simply
 * not present in the map. A relation key may be any string — the spec permits
 * arbitrary link relations alongside the reserved ones.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#document-links
 */
abstract readonly class AbstractLinks
{
    /** @var array<string, Link> */
    protected array $links;

    /**
     * @param array<string, Link|null> $links
     */
    public function __construct(
        public string $baseUri = '',
        array $links = [],
    ) {
        $filtered = [];
        foreach ($links as $name => $link) {
            if ($link !== null) {
                $filtered[$name] = $link;
            }
        }

        $this->links = $filtered;
    }

    public function link(string $name): ?Link
    {
        return $this->links[$name] ?? null;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $links = [];

        foreach ($this->links as $rel => $link) {
            $links[$rel] = $link->transform($this->baseUri);
        }

        return $links;
    }
}
