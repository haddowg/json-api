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
        $href = self::prefix($this->href, $baseUri);

        if ($this->meta === []) {
            return $href;
        }

        return [
            'href' => $href,
            'meta' => $this->meta,
        ];
    }

    /**
     * Prepends the base URI to a relative href. An empty href is left as-is (an
     * absent link), and an href that is already absolute — it carries a scheme
     * (`https://…`) or is protocol-relative (`//host/…`) — is returned untouched, so
     * an author-supplied absolute URL (a common shape for an error `about`/`type`
     * documentation link) is never corrupted by a base prefix.
     *
     * @internal
     */
    protected static function prefix(string $href, string $baseUri): string
    {
        if ($href === '' || self::isAbsolute($href)) {
            return $href;
        }

        return $baseUri . $href;
    }

    private static function isAbsolute(string $href): bool
    {
        // Protocol-relative (`//host/path`) or a scheme-qualified absolute URL
        // (`scheme://…` per RFC 3986 §3.1: ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )).
        return \str_starts_with($href, '//')
            || \preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $href) === 1;
    }
}
