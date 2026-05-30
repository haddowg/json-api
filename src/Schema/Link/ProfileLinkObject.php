<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * Represents a JSON:API profile link object.
 *
 * A profile link carries an `aliases` map alongside the standard link object
 * members, allowing a document to rename a profile's keywords. The actual
 * association of profiles with a document is fleshed out in Phase 2; this VO
 * only models the link itself.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#profiles
 */
final readonly class ProfileLinkObject extends LinkObject
{
    /**
     * @param array<string, string> $aliases
     * @param array<string, mixed>  $meta
     */
    public function __construct(
        string $href,
        public array $aliases = [],
        string $rel = '',
        string $title = '',
        string $type = '',
        string $hreflang = '',
        array $meta = [],
    ) {
        parent::__construct($href, $rel, $title, $type, $hreflang, $meta);
    }

    /**
     * Return the alias registered for the given profile keyword, or '' if none.
     */
    public function alias(string $keyword): string
    {
        return $this->aliases[$keyword] ?? '';
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function transform(string $baseUri): array
    {
        $link = parent::transform($baseUri);
        $link['aliases'] = $this->aliases;

        return $link;
    }
}
