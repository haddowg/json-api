<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

/**
 * The outcome of matching a request `Content-Type` against the media types an endpoint
 * accepts ({@see ContentTypeMatcher::match()}).
 */
final readonly class ContentTypeMatch
{
    /**
     * @param string $mediaType      the request's media type, lowercased and without parameters
     * @param string $accepted       the accepted entry it matched, lowercased and without
     *                               parameters; it may be a wildcard such as `image/*`
     * @param bool   $corsSafelisted whether the request's media type is one a browser sends
     *                               cross-origin without a preflight
     */
    public function __construct(
        public string $mediaType,
        public string $accepted,
        public bool $corsSafelisted,
    ) {}

    /**
     * Whether the accepted entry names the media type itself rather than a wildcard.
     */
    public function isExplicit(): bool
    {
        return $this->accepted === $this->mediaType;
    }
}
