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
     * Returns a copy of this link container bound to `$baseUri`, but only when it
     * carries no base of its own — a container constructed with an explicit base
     * (a `withBaseUri(...)` author choice or a configured canonical host) is
     * returned unchanged, so a deliberate base always wins over the request-derived
     * one. Used by the error-render path to thread the resolved request origin into
     * author-supplied error links without disturbing the base they already pinned.
     *
     * @internal
     */
    public function rebasedTo(string $baseUri): static
    {
        if ($this->baseUri !== '') {
            return $this;
        }

        return $this->reboundTo($baseUri);
    }

    /**
     * Reconstructs this container with a new base URI, preserving its links (and any
     * subclass-specific members). Each concrete container rebuilds itself from its
     * own public members.
     *
     * @internal
     */
    abstract protected function reboundTo(string $baseUri): static;

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
